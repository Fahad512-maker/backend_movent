<?php

namespace App\Services;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Invoice;
use App\Models\Lead;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Suggests a Project Title for a Lead by reading their Sales Chat.
 *
 * THE SALES CHAT IS THE ONLY SOURCE. Not the lead's name, not their company
 * name, not the CRM's deal fields, not a house list of common project types.
 * If the conversation does not actually say what is being built, this returns
 * null and the Create Invoice screen shows no suggestion at all — an invented
 * title is worse than none, because it looks authoritative and gets accepted
 * with a Tab press.
 *
 * That rule is enforced in three places, because a prompt alone is not a
 * guarantee:
 *   1. hasEnoughToWorkWith() refuses to call the model when the transcript is
 *      too thin to contain a requirement. This also means an empty or
 *      one-line chat costs nothing.
 *   2. The prompt itself demands the empty string when the chat is
 *      insufficient (see prompt()).
 *   3. isUsableTitle() throws away answers that came back generic anyway, or
 *      that are just the lead's own name echoed back.
 *
 * suggest() is cached per Lead, keyed on a hash of the exact transcript +
 * attachment filenames it would send the model — see suggestCacheKey().
 * Reloading the Create Invoice screen for the SAME lead, with no new chat
 * activity since the last suggestion, is a cache hit and never calls the AI
 * again (the Create Invoice page currently re-requests this on every mount,
 * see frontend/app/invoices/new/page.tsx — this is what stops that from
 * burning a live model call, and a shared free-tier quota, on every reload).
 * The moment a new message/attachment changes the transcript, the hash
 * changes, the cache key changes, and the very next call re-analyses fresh —
 * so "reflect a new message immediately" still holds, it just no longer
 * means "re-run the model for an unchanged conversation too".
 *
 * The NULL outcome ("no suggestion") is cached too, deliberately — unlike
 * AiServiceCatalogService's catalog cache, which only caches a real result
 * so a dead key retries on the next load. Here a null result is content-
 * addressed the same as a real one: if the transcript hasn't changed, the
 * model would refuse again for the exact same reason, so there is nothing
 * to gain by re-asking, only another wasted call.
 *
 * Same shared multi-provider client as AiServiceCatalogService:
 * App\Services\AiRouter (Groq -> Gemini -> OpenRouter, all free-tier only).
 * No key, no credit, a bad model or an unparseable reply on any provider all
 * return null rather than throwing — a missing suggestion must never block
 * invoice creation.
 */
class LeadProjectTitleService
{
    /**
     * Far more than a 2-6 word title needs, and deliberately so.
     *
     * The configured model may be a REASONING model — OPENROUTER_MODEL=
     * 'openrouter/free' auto-routes to whichever free model is currently
     * available, which can land on one — and those spend the token budget
     * thinking before emitting a single visible character. A tight budget
     * does not produce a short title, it produces finish_reason='length'
     * with content=null and therefore no suggestion at all, on every call.
     * Measured against an earlier pinned reasoning model (nex-agi/
     * nex-n2.5-mini:free): at 60 it returned nothing at all, at 800 and
     * again at 2000 it still ran out on the longer transcripts. How long it
     * thinks varies run to run and is not bounded by the size of the
     * answer, so the budget has to cover the worst case. Unused budget is
     * free — billing is on tokens actually produced, and a 3-word answer
     * spends 3 — so this is sized generously on purpose.
     * AiServiceCatalogService allows 1400 for a far larger visible answer.
     *
     * If suggestions still come back empty with finish_reason='length' in the
     * log, the model is the problem, not this number: point OPENROUTER_MODEL
     * at a non-reasoning model and it will answer in a handful of tokens.
     *
     * The visible answer stays short regardless: MAX_TITLE_LENGTH below
     * rejects anything that comes back as prose.
     */
    private const MAX_TOKENS = 4000;

    /** Titles longer than this are prose, not a title. */
    private const MAX_TITLE_LENGTH = 80;
    private const MAX_TITLE_WORDS = 7;

    /**
     * The transcript has to carry at least this much before a requirement can
     * plausibly be in it. Two messages is a greeting; a few hundred
     * characters is a conversation.
     */
    private const MIN_MESSAGES = 2;
    private const MIN_TRANSCRIPT_CHARS = 60;

    /**
     * Same idea as MIN_TRANSCRIPT_CHARS, but for suggestProjectName()'s wider
     * pool of sources (requirement doc + chat + lead info + invoice items) —
     * only worth a network call once there's a plausible amount of real
     * content somewhere in that combined corpus. Invoice line items require a
     * non-empty description already, so this rarely blocks a call in
     * practice; isUsableTitle()/isGroundedIn() remain the real safety net.
     */
    private const MIN_PROJECT_CONTEXT_CHARS = 20;

    /**
     * suggestProjectName() runs synchronously inside the invoice-payment path
     * (App\Services\PaymentProjectStartService), which can be reached from a
     * payment gateway webhook — unlike suggest()'s 20s budget (a dedicated,
     * user-clicked GET endpoint), a long hang here risks the webhook response
     * itself. Shorter, not absent: still a real attempt, just bounded so a
     * slow provider can't stall a payment confirmation. Combined with
     * PROJECT_NAME_MAX_MODELS below, the worst case (every model timing out)
     * is capped at roughly TIMEOUT × MAX_MODELS.
     */
    private const PROJECT_NAME_TIMEOUT_SECONDS = 12;

    /**
     * How many of freeModels() this path will try before giving up — fewer
     * than suggest()'s "try every configured model" (no webhook waiting on
     * that one), so a run of failures here can't chain into a very long
     * total wait on the payment path.
     */
    private const PROJECT_NAME_MAX_MODELS = 3;

    /**
     * Answers to throw away even if the model returns them. These are exactly
     * the "random/general title" the feature exists to prevent — a model asked
     * for a title will happily produce one of these from a chat that said
     * nothing at all.
     */
    private const GENERIC_TITLES = [
        'project', 'new project', 'website', 'new website', 'website project',
        'web development', 'website development', 'mobile app', 'app development',
        'software project', 'client project', 'custom project', 'general project',
        'business website', 'untitled project', 'n/a', 'none', 'unknown',
    ];

    /**
     * @return string|null The suggested title, or null when the conversation
     *                     does not support one (no chat, too little said, no
     *                     API key, provider failure, or a generic answer).
     */
    public static function suggest(Lead $lead): ?string
    {
        [$lines, $spoken] = self::transcript($lead);
        // A chat that is JUST a shared file (a PDF requirement doc, say) with
        // no actual typed message has an empty transcript — hasEnoughToWorkWith()
        // alone would refuse to even try. The filename is real signal on its
        // own, so its presence is enough to attempt a suggestion; askModel()
        // still applies the exact same isUsableTitle()/isGroundedIn() guards
        // before returning anything.
        $attachmentNames = self::chatAttachmentFilenames($lead);

        if (!self::hasEnoughToWorkWith($lines) && $attachmentNames === []) {
            return null;
        }

        $cacheKey = self::suggestCacheKey($lead, $lines, $attachmentNames);

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && array_key_exists('title', $cached)) {
            return $cached['title'];
        }

        $title = self::askModel($lines, $spoken, $lead, $attachmentNames);

        if ($title === null) {
            // The strict pass above requires the title's words to literally
            // appear in the transcript/filenames — right for a real, specific
            // requirement, but it means a weak signal (a generically-named
            // file like "1.pdf", a one-line chat) always comes back empty
            // even though *something* short and professional could
            // reasonably be offered. This second, relaxed pass is the
            // explicit trade-off requested: a plausible best-effort name is
            // better than nothing here. It still never uses the lead's
            // personal identity or a bare placeholder ("New Project") —
            // isUsableTitle() still applies — it just isn't required to
            // prove every word came from the chat itself.
            $title = self::askModelRelaxed($lines, $lead, $attachmentNames);
        }

        // Cached even when null — see the class docblock for why a "no
        // suggestion" outcome is worth caching here, unlike
        // AiServiceCatalogService's catalog cache.
        Cache::put($cacheKey, ['title' => $title], now()->addDay());

        return $title;
    }

    /**
     * Content-addressed rather than time-addressed: the key changes the
     * moment the transcript, attachment filenames, or company name do, so a
     * stale suggestion is never served — there's nothing to invalidate on a
     * new chat message because the new message simply produces a different
     * key. The 24h TTL passed alongside this key (see suggest()) isn't for
     * correctness, only to let old keys for since-gone-cold leads eventually
     * drop out of the cache store.
     */
    private static function suggestCacheKey(Lead $lead, array $lines, array $attachmentNames): string
    {
        $fingerprint = implode("\n", $lines) . '|' . implode(',', $attachmentNames) . '|' . $lead->company_name;

        return 'lead:ai_project_title:' . $lead->id . ':' . md5($fingerprint);
    }

    /**
     * Suggests a Project NAME at the moment a paid invoice actually creates
     * the project — see App\Services\PaymentProjectStartService::
     * projectName(), the only caller. Unlike suggest() above (Sales Chat
     * only, offered as a manual pick on the invoice form), this draws on
     * every source available by the time payment lands, in priority order:
     *
     *   1. The lead's own confirmed requirements (proposed_project_title,
     *      service_category, scope_summary, detailed_scope) — the closest
     *      thing this codebase has to a "requirement document": free-text
     *      fields captured when a deal is marked won, not a separate
     *      versioned document (none exists).
     *   2. The Sales Chat transcript (reuses transcript() verbatim), plus
     *      the FILENAMES of any files shared in that same chat (see
     *      chatAttachmentFilenames() — filenames only, not file content: no
     *      PDF/DOCX text-extraction exists in this codebase, and a filename
     *      like "Shopify_Requirements.pdf" is still a useful hint on its own).
     *   3. Lead identity/category — lowest priority, background only; never
     *      allowed to leak into the title itself (isUsableTitle() still
     *      strips any title that is just the lead's name/company).
     *   4. The invoice's own purpose/line items — always available, even for
     *      a lead-less/guest invoice.
     *
     * Same non-negotiable rule as suggest(): a missing or unusable answer
     * returns null and the caller falls back to its own existing chain.
     * Never throws, never blocks project creation.
     *
     * @return string|null The suggested name, or null when nothing in the
     *                      combined context supports one.
     */
    public static function suggestProjectName(Invoice $invoice): ?string
    {
        $lead = $invoice->lead;

        $requirementDoc = self::requirementDocText($lead);
        [$chatLines, $chatSpoken] = $lead ? self::transcript($lead) : [[], ''];
        $attachmentNames = $lead ? self::chatAttachmentFilenames($lead) : [];
        $leadInfo = self::leadInfoText($lead);
        $invoiceContext = self::invoiceContextText($invoice);

        $attachmentText = $attachmentNames !== [] ? 'Files shared in chat: ' . implode(', ', $attachmentNames) : '';

        $corpus = trim(implode(' ', array_filter([$requirementDoc, $chatSpoken, $attachmentText, $invoiceContext])));

        if (mb_strlen($corpus) < self::MIN_PROJECT_CONTEXT_CHARS) {
            return null;
        }

        return self::askModelForProjectName(
            $invoice,
            $lead,
            $requirementDoc,
            $chatLines,
            $attachmentText,
            $leadInfo,
            $invoiceContext,
            $corpus
        );
    }

    /**
     * Filenames only, most recent first, deduplicated — NOT file content.
     * No PDF/DOCX/image text-extraction exists in this codebase; a filename
     * such as "Shopify_Requirements.pdf" is still a free, safe signal on its
     * own, without adding a new parsing dependency or downloading/opening
     * untrusted uploaded files. Same thread-resolution as transcript() (a
     * lead's chat may have moved into a project thread post-payment), kept
     * as its own query rather than folded into transcript() so suggest()'s
     * existing text-only behavior (and its whereNotNull('content') filter,
     * which would otherwise hide an attachment-only message) is untouched.
     */
    private static function chatAttachmentFilenames(Lead $lead): array
    {
        $salesThread = ChatThread::where('thread_type', 'sales')
            ->where('linked_to_type', 'Lead')
            ->where('linked_to_id', $lead->id)
            ->first();

        if (!$salesThread) {
            return [];
        }

        $thread = SalesConversationResolver::resolve($salesThread, 'Lead', $lead->id);

        return ChatMessage::where('thread_id', $thread->id)
            ->where('is_deleted', false)
            ->where('visibility', 'client')
            ->whereNotNull('attachment_name')
            ->orderByDesc('sent_at')
            ->limit(10)
            ->pluck('attachment_name')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The conversation as "Lead:" / "Our team:" lines.
     *
     * Resolves through SalesConversationResolver for the same reason every
     * other reader does: once the lead's invoice is paid the conversation
     * moves into the project's thread, and reading the drained sales thread
     * would see nothing. visibility='client' is the slice both sides of this
     * conversation actually exchanged — internal team chatter on a project
     * thread is not part of what the lead asked for and must not steer the
     * title.
     *
     * Returns TWO views of the same messages, and the split matters:
     *   [0] labelled lines ("Lead: ...") — what the model reads, because who
     *       said what changes meaning ("we could also build you an app" is an
     *       offer; "I also need an app" is a requirement).
     *   [1] the spoken words alone — what isGroundedIn() checks against. The
     *       speaker labels must NOT be part of that haystack: with them, a
     *       greetings-only chat produced "Checking In With Lead", grounded on
     *       the literal word "Lead" from this method's own prefix.
     *
     * @return array{0: array<int, string>, 1: string}
     */
    private static function transcript(Lead $lead): array
    {
        $salesThread = ChatThread::where('thread_type', 'sales')
            ->where('linked_to_type', 'Lead')
            ->where('linked_to_id', $lead->id)
            ->first();

        if (!$salesThread) {
            return [[], ''];
        }

        $thread = SalesConversationResolver::resolve($salesThread, 'Lead', $lead->id);

        $messages = ChatMessage::where('thread_id', $thread->id)
            ->where('is_deleted', false)
            ->where('visibility', 'client')
            ->whereNotNull('content')
            ->with(['sender:id,name', 'senderAdmin:id,name'])
            // A long negotiation would blow the context window and bury the
            // requirement; the most recent exchanges are also the ones most
            // likely to hold the current scope. Fetched newest-first so the
            // limit actually keeps the LATEST 80 (not the oldest 80 — the
            // opposite of "recent" for any thread past that size), then
            // reversed back to chronological order below for the model to
            // read the conversation the way it happened.
            ->orderByDesc('sent_at')
            ->limit(80)
            ->get()
            ->reverse()
            ->values()
            ->filter(fn (ChatMessage $m) => strlen(trim((string) $m->content)) > 2);

        $lines = $messages->map(function (ChatMessage $m) {
            // guest_sender_name with no sender/sender_admin is the lead
            // writing in through their own link or the invoice page.
            $isLead = is_null($m->sender_id) && is_null($m->sender_admin_id);

            return ($isLead ? 'Lead' : 'Our team') . ': ' . trim((string) $m->content);
        })->values()->all();

        $spoken = $messages->map(fn (ChatMessage $m) => trim((string) $m->content))->implode(' ');

        return [$lines, $spoken];
    }

    private static function hasEnoughToWorkWith(array $transcript): bool
    {
        if (count($transcript) < self::MIN_MESSAGES) {
            return false;
        }

        return strlen(implode("\n", $transcript)) >= self::MIN_TRANSCRIPT_CHARS;
    }

    /**
     * Priority #1 for suggestProjectName(): the closest thing this codebase
     * has to a "requirement document" — the lead's own confirmed-requirement
     * fields, captured when a deal is marked won (App\Services\
     * LeadDealService::markWonFromPayment()) or set by hand on the deal.
     * There is no separate versioned requirement-document table; these plain
     * columns ARE the final confirmed requirements.
     */
    private static function requirementDocText(?Lead $lead): string
    {
        if (!$lead) {
            return '';
        }

        $parts = [];
        if (trim((string) $lead->proposed_project_title) !== '') {
            $parts[] = 'Proposed Title: ' . trim($lead->proposed_project_title);
        }
        if (trim((string) $lead->service_category) !== '') {
            $parts[] = 'Service Category: ' . trim($lead->service_category);
        }
        if (trim((string) $lead->scope_summary) !== '') {
            $parts[] = 'Scope Summary: ' . trim($lead->scope_summary);
        }
        if (trim((string) $lead->detailed_scope) !== '') {
            $parts[] = 'Detailed Scope: ' . trim($lead->detailed_scope);
        }

        return implode("\n", $parts);
    }

    /**
     * Priority #3 for suggestProjectName() — background identity/category
     * only. Never a source of title words on its own: isUsableTitle() still
     * strips any title built from the lead's name or equal to their company.
     */
    private static function leadInfoText(?Lead $lead): string
    {
        if (!$lead) {
            return '';
        }

        $parts = [];
        if (trim((string) $lead->name) !== '') {
            $parts[] = 'Contact Name: ' . trim($lead->name);
        }
        if (trim((string) $lead->company_name) !== '') {
            $parts[] = 'Company: ' . trim($lead->company_name);
        }
        if (trim((string) $lead->service_category) !== '') {
            $parts[] = 'Service Category: ' . trim($lead->service_category);
        }

        return implode("\n", $parts);
    }

    /**
     * Priority #4 for suggestProjectName() — always available, even for a
     * lead-less/guest invoice with no chat and no deal fields at all.
     */
    private static function invoiceContextText(Invoice $invoice): string
    {
        $parts = [];
        if (trim((string) $invoice->invoice_purpose) !== '') {
            $parts[] = 'Purpose: ' . trim($invoice->invoice_purpose);
        }

        $itemLines = $invoice->items
            ->map(fn ($item) => trim((string) $item->description))
            ->filter(fn (string $d) => $d !== '')
            ->map(fn (string $d) => '- ' . $d)
            ->values()
            ->all();

        if ($itemLines !== []) {
            $parts[] = "Items:\n" . implode("\n", $itemLines);
        }

        return implode("\n", $parts);
    }

    private static function projectNamePrompt(): string
    {
        return <<<'PROMPT'
Analyze the client's final requirement document, relevant conversation, lead
information, and purchased service. Generate one short and professional
project name representing the final agreed work.

Priority:
1. Latest Requirement Document
2. Relevant Client/Seller conversation
3. Lead/service information
4. Invoice description

Return only the project name.
Do not return explanation, numbering, quotes, alternatives, or extra text.

Additional rules:
- 2 to 6 words, Title Case.
- Never use the client's personal name or company name as the project name.
- Do not invent requirements, technologies or features the given context does
  not mention.
- If none of the given context states what is being built, respond with
  exactly: NONE
PROMPT;
    }

    /**
     * The single place every AI call in this class goes through — delegates
     * to App\Services\AiRouter, the Groq -> Gemini -> OpenRouter fallback
     * chain shared with App\Services\AiServiceCatalogService, so the retry
     * logic exists in exactly one place. $maxModels caps how many OpenRouter
     * free models (the last provider tried) are attempted — null (the
     * default, used by suggest()/askModelRelaxed()) tries all of them;
     * askModelForProjectName() passes a small cap instead, since that path
     * runs inside the invoice-payment flow and a long run of failures must
     * not chain into a very long wait there.
     */
    private static function callAi(array $messages, int $timeoutSeconds, string $logPrefix, array $logContext = [], ?int $maxModels = null): ?array
    {
        return AiRouter::complete($messages, $timeoutSeconds, self::MAX_TOKENS, $logPrefix, $logContext, $maxModels);
    }

    /**
     * Same OpenRouter request shape as askModel() above (config, headers,
     * try/catch-and-log-and-return-null philosophy) — a shorter timeout only
     * (see PROJECT_NAME_TIMEOUT_SECONDS), since this runs inside the
     * invoice-payment path rather than behind a dedicated GET endpoint.
     */
    private static function askModelForProjectName(
        Invoice $invoice,
        ?Lead $lead,
        string $requirementDoc,
        array $chatLines,
        string $attachmentText,
        string $leadInfo,
        string $invoiceContext,
        string $corpus
    ): ?string {
        $sections = [];
        if ($requirementDoc !== '') {
            $sections[] = "=== Latest Requirement Document (HIGHEST PRIORITY — final confirmed requirements) ===\n{$requirementDoc}";
        }
        if ($chatLines !== [] || $attachmentText !== '') {
            $chatSection = "=== Relevant Chat History ===\n" . implode("\n", $chatLines);
            if ($attachmentText !== '') {
                $chatSection .= ($chatLines !== [] ? "\n" : '') . $attachmentText;
            }
            $sections[] = $chatSection;
        }
        if ($leadInfo !== '') {
            $sections[] = "=== Lead Info ===\n{$leadInfo}";
        }
        if ($invoiceContext !== '') {
            $sections[] = "=== Invoice Details ===\n{$invoiceContext}";
        }

        $response = self::callAi(
            [
                ['role' => 'system', 'content' => self::projectNamePrompt()],
                ['role' => 'user',   'content' => implode("\n\n", $sections)],
            ],
            self::PROJECT_NAME_TIMEOUT_SECONDS,
            '[ai-project-name]',
            ['invoice_id' => $invoice->id],
            self::PROJECT_NAME_MAX_MODELS
        );

        if ($response === null) {
            return null;
        }

        $name = self::clean((string) ($response['content'] ?? ''));

        if (!self::isUsableTitle($name, $lead)
            || !self::isGroundedInProjectContext($name, $corpus)
            || self::describesSalesProcessOnly($name)) {
            Log::info('[ai-project-name] no usable name — offering no suggestion', [
                'invoice_id' => $invoice->id,
                'provider'   => $response['provider'] ?? null,
                'model'      => $response['model'] ?? null,
                'finish'     => $response['finish_reason'] ?? null,
                'returned'   => $name === '' ? '(empty)' : $name,
            ]);

            return null;
        }

        return $name;
    }

    private static function askModel(array $transcript, string $spoken, Lead $lead, array $attachmentNames = []): ?string
    {
        // Filenames only (see chatAttachmentFilenames()) — a chat that is
        // just a shared PDF with no typed message has an empty $transcript;
        // the filename is the only real signal in that case, folded into
        // both what the model reads and what isGroundedIn() checks the
        // answer against.
        $attachmentText = $attachmentNames !== [] ? 'Files shared in chat: ' . implode(', ', $attachmentNames) : '';
        $groundingText  = trim($spoken . ' ' . $attachmentText);
        $userContent    = "Sales Chat transcript:\n\n" . implode("\n", $transcript)
            . ($attachmentText !== '' ? "\n\n{$attachmentText}" : '');

        $response = self::callAi(
            [
                ['role' => 'system', 'content' => self::prompt()],
                ['role' => 'user',   'content' => $userContent],
            ],
            20,
            '[ai-project-title]',
            ['lead_id' => $lead->id]
        );

        if ($response === null) {
            return null;
        }

        $title = self::clean((string) ($response['content'] ?? ''));

        if (!self::isUsableTitle($title, $lead)
            || !self::isGroundedIn($title, $groundingText)
            || self::describesSalesProcessOnly($title)) {
            // Logged because "the call succeeded but you got no suggestion" is
            // otherwise invisible and indistinguishable from "the model was
            // never called". finish_reason is included deliberately: 'length'
            // with empty content is the signature of a reasoning model that
            // ran out of budget before writing anything (see MAX_TOKENS), not
            // of a chat that lacked requirements.
            Log::info('[ai-project-title] no usable title — offering no suggestion', [
                'lead_id'  => $lead->id,
                'provider' => $response['provider'] ?? null,
                'model'    => $response['model'] ?? null,
                'finish'   => $response['finish_reason'] ?? null,
                'returned' => $title === '' ? '(empty)' : $title,
            ]);

            return null;
        }

        return $title;
    }

    /**
     * Second-chance pass used only when askModel() above found nothing —
     * see suggest()'s comment for why this exists. Uses whatever background
     * is available (company name, filenames, any chat text) to make one
     * plausible, professional-sounding guess rather than none at all.
     * Still runs through isUsableTitle() (length/word-count/generic-title/
     * lead-identity checks all still apply) — it just skips isGroundedIn()
     * and describesSalesProcessOnly(), which specifically police "did this
     * word actually appear in the transcript", the exact requirement this
     * relaxed pass is deliberately allowed to not meet.
     */
    private static function askModelRelaxed(array $transcript, Lead $lead, array $attachmentNames): ?string
    {
        $context = [];
        if ($transcript !== []) {
            $context[] = "Sales Chat transcript:\n" . implode("\n", $transcript);
        }
        if ($attachmentNames !== []) {
            $context[] = 'Files shared in chat: ' . implode(', ', $attachmentNames);
        }
        if (trim((string) $lead->company_name) !== '') {
            $context[] = 'Client business: ' . trim($lead->company_name);
        }
        if ($context === []) {
            $context[] = '(No chat content, files, or business details available.)';
        }

        $response = self::callAi(
            [
                ['role' => 'system', 'content' => self::relaxedPrompt()],
                ['role' => 'user',   'content' => implode("\n\n", $context)],
            ],
            20,
            '[ai-project-title]',
            ['lead_id' => $lead->id]
        );

        if ($response === null) {
            return null;
        }

        $title = self::clean((string) ($response['content'] ?? ''));

        if (!self::isUsableTitle($title, $lead)) {
            Log::info('[ai-project-title] relaxed pass produced no usable title', [
                'lead_id'  => $lead->id,
                'provider' => $response['provider'] ?? null,
                'model'    => $response['model'] ?? null,
                'returned' => $title === '' ? '(empty)' : $title,
            ]);

            return null;
        }

        Log::info('[ai-project-title] relaxed fallback used', [
            'lead_id'  => $lead->id,
            'provider' => $response['provider'] ?? null,
            'model'    => $response['model'] ?? null,
            'title'    => $title,
        ]);

        return $title;
    }

    private static function relaxedPrompt(): string
    {
        return <<<'PROMPT'
You name software/service projects for a sales CRM. The strict information
available (chat, shared file names, client business) is limited or unclear.

Make ONE reasonable, professional best-effort guess at a short project name,
using whatever hints are present — a filename's wording, the type of business,
anything suggestive of the kind of work involved. A sensible general guess
(e.g. "Business Plan Proposal", "Website Development Project") is fine here
even if not every word is explicitly stated.

Output rules:
- Output the name alone. No quotes, no prose, no explanation, no punctuation
  at the end, no "Project Title:" prefix.
- 2 to 6 words. Title Case.
- Do NOT use the client's personal name or company name.
- Only if there is truly nothing to base even a general guess on, output
  exactly: NONE
PROMPT;
    }

    private static function prompt(): string
    {
        return <<<'PROMPT'
You name software projects for a sales CRM. You are given the transcript of a
Sales Chat between a prospective customer ("Lead") and the agency ("Our team"),
and, when the Lead shared one, the filename of a file they uploaded in that
chat (e.g. a requirement document).

Your ONLY job: output a short project title describing the thing the Lead has
actually asked to have built, using only what the transcript (and, if given,
the shared file's name) states. A descriptive filename on its own — e.g.
"Shopify_Store_Requirements.pdf" — can be enough even if the Lead typed
nothing; a meaningless one ("IMG_2384.jpg", "Document1.pdf") is not.

Output rules:
- Output the title alone. No quotes, no prose, no explanation, no punctuation
  at the end, no "Project Title:" prefix.
- 2 to 6 words. Title Case.
- It must be built from requirements the Lead actually stated in the
  transcript — the type of product, what it sells or does, and the major
  capabilities they named.
- If the Lead named several distinct deliverables, combine them
  (e.g. "E-Commerce Website & Mobile App").

CRITICAL — when NOT to answer:
- If the transcript does not clearly state what the Lead wants built, output
  exactly: NONE
- Do NOT guess, and do NOT fall back to a generic title such as "New Project",
  "Website Development" or "Business Website".
- Do NOT use the Lead's personal name or their company name in the title.
- Do NOT invent requirements, technologies, industries or features that the
  transcript does not mention.
- Small talk, greetings, pricing, budget or scheduling talk alone are NOT a
  requirement. If that is all the transcript contains, output NONE.
- Every significant word in your title must come from the transcript. If you
  cannot build a title out of words the Lead actually used, output NONE.

Deciding:
- First ask yourself: does the transcript name a thing to build? If not, the
  only correct answer is NONE.
- NONE is a good answer. It is far better than a plausible guess, because the
  user will accept whatever you suggest with a single keystroke.

No worked example of a title is given here on purpose: reproducing an example
instead of reading the transcript is a failure, not an answer.
PROMPT;
    }

    /**
     * Is this title actually built from words the conversation contains?
     *
     * This is the guard that really enforces "the Sales Chat is the only
     * source of truth", and it exists because the prompt alone demonstrably
     * does not. Measured against the current model: a chat containing nothing
     * but "Hi, good morning" / "How can we help?" / "Just checking in"
     * produced "E-Commerce Clothing Store" — lifted wholesale from the worked
     * example the prompt used to carry — and a chat about rates and a Tuesday
     * call produced "Tuesday Sales Consultation". Both looked entirely
     * plausible in the UI, which is exactly what makes them dangerous: the
     * user accepts one with a Tab press.
     *
     * The rule: strip grammatical filler and the scaffolding nouns a model
     * tacks on ("Platform", "Solution", "Portal"), then require at least two
     * of the remaining words — and at least half of them — to appear in the
     * transcript. A genuine title rephrases the conversation, so it keeps the
     * conversation's own nouns ("clothing", "e-commerce", "attendance"); an
     * invented one has nothing to anchor to.
     *
     * Comparison is loose on purpose — punctuation is stripped so "E-Commerce"
     * matches "e-commerce", and a trailing plural 's' is dropped so "grades"
     * matches "grade" — because rejecting a real title is a cost too.
     */
    private static function isGroundedIn(string $title, string $spoken): bool
    {
        $haystack = self::normalizeWords($spoken);

        $significant = array_values(array_diff(
            self::normalizeWords($title),
            self::IGNORED_TITLE_WORDS
        ));

        if ($significant === []) {
            // Nothing but filler and scaffolding — carries no requirement.
            return false;
        }

        $grounded = array_filter(
            $significant,
            fn (string $word) => in_array($word, $haystack, true)
        );

        return count($grounded) >= 2 && count($grounded) * 2 >= count($significant);
    }

    /**
     * isGroundedIn()'s counterpart for suggestProjectName(), tuned for a
     * richer, more reliable corpus (structured lead requirement fields +
     * invoice details, not just a thin chat transcript) rather than a
     * looser standard overall:
     *
     *   - Stem-matched (first 5 chars) instead of exact-token, so a
     *     reasonable paraphrase — "Storefront" for a corpus that says
     *     "store", "Ecommerce" for "e-commerce" — still grounds. Measured
     *     example: a lead with detailed_scope "Build a Shopify e-commerce
     *     website with custom product pages..." produced the title "Custom
     *     Shopify Storefront", correctly on-topic but rejected by the
     *     exact-token isGroundedIn() because "storefront" never literally
     *     appears — only "store" does.
     *   - Only ONE grounded word required, not two-and-half. The richer
     *     source pool here already does the anti-hallucination work
     *     isGroundedIn()'s stricter bar exists for on thin chat alone.
     */
    private static function isGroundedInProjectContext(string $title, string $corpus): bool
    {
        $significant = array_values(array_diff(
            self::normalizeWords($title),
            self::IGNORED_TITLE_WORDS
        ));

        if ($significant === []) {
            return false;
        }

        $corpusStems = array_map(
            fn (string $w) => mb_substr($w, 0, 5),
            self::normalizeWords($corpus)
        );

        $grounded = array_filter(
            $significant,
            fn (string $word) => in_array(mb_substr($word, 0, 5), $corpusStems, true)
        );

        return $grounded !== [];
    }

    /**
     * Does this title describe the SALES CONVERSATION rather than a thing to
     * build?
     *
     * isGroundedIn() cannot catch this on its own: a chat about rates and a
     * Tuesday call produced "Rate Quote Call Tuesday", and every one of those
     * words genuinely appears in the transcript — it is grounded, it is just
     * not a project. Talking about price and scheduling a call is what happens
     * before a requirement exists, which is precisely when no title should be
     * offered.
     *
     * Rejects only when EVERY significant word is process vocabulary. One real
     * noun is enough to survive, so "Clothing Store With Online Payments"
     * keeps its suggestion even though "payment" is on the list.
     */
    private static function describesSalesProcessOnly(string $title): bool
    {
        $significant = array_values(array_diff(
            self::normalizeWords($title),
            self::IGNORED_TITLE_WORDS
        ));

        if ($significant === []) {
            return true;
        }

        return array_diff($significant, self::SALES_PROCESS_WORDS) === [];
    }

    /**
     * Vocabulary of the sales process itself — money, meetings and calendars.
     * A title made of nothing but these describes the negotiation, not the
     * deliverable. Weekdays and months are here for the same reason.
     */
    private const SALES_PROCESS_WORDS = [
        'call', 'meeting', 'quote', 'quotation', 'rate', 'price', 'pricing',
        'cost', 'costing', 'budget', 'estimate', 'proposal', 'consultation',
        'discussion', 'discuss', 'followup', 'follow', 'enquiry', 'inquiry',
        'payment', 'term', 'invoice', 'deal', 'sale', 'client', 'customer',
        'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday',
        'sunday', 'week', 'month', 'today', 'tomorrow', 'morning', 'afternoon',
        'january', 'february', 'march', 'april', 'may', 'june', 'july',
        'august', 'september', 'october', 'november', 'december',
    ];

    /**
     * Lowercased, punctuation-free words with a trailing plural 's' removed,
     * so "E-Commerce" -> "ecommerce" and "products" -> "product" on both
     * sides of the comparison.
     *
     * @return array<int, string>
     */
    private static function normalizeWords(string $text): array
    {
        $text = mb_strtolower($text);
        // Hyphens close up rather than split: "e-commerce" is one word here,
        // and splitting it would make "e" and "commerce" two weak tokens.
        $text = str_replace(['-', '/', '_'], ['', ' ', ' '], $text);
        $text = preg_replace('/[^a-z0-9\s]+/u', ' ', $text) ?? $text;

        return collect(preg_split('/\s+/', trim($text)) ?: [])
            ->filter(fn (string $w) => mb_strlen($w) > 2)
            ->map(fn (string $w) => mb_strlen($w) > 3 && str_ends_with($w, 's') ? mb_substr($w, 0, -1) : $w)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Words a title may contain without them proving anything about the
     * conversation: grammatical filler, plus the generic nouns a model reaches
     * for to round a title off. "Website" and "app" are deliberately NOT here
     * — those are real deliverables and should have to appear in the chat.
     */
    private const IGNORED_TITLE_WORDS = [
        'and', 'the', 'for', 'with', 'from', 'into', 'plus', 'per',
        'platform', 'portal', 'solution', 'system', 'project', 'suite', 'hub',
        'service', 'services', 'development', 'custom', 'new', 'based',
    ];

    /** Strips the wrappers a model adds even when told not to. */
    private static function clean(string $text): string
    {
        $title = trim($text);
        $title = preg_replace('/^(project\s*title|title)\s*[:\-]\s*/i', '', $title) ?? $title;
        $title = trim($title, " \t\n\r\0\x0B\"'`*.");
        // Collapse any newline/indent the model used into single spaces.
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);

        return $title;
    }

    /**
     * The last line of defence against exactly what this feature must not do:
     * hand the user a confident-looking title the conversation never supported.
     *
     * $lead is nullable so suggestProjectName() below can vet a title for a
     * lead-less/guest invoice too — the identity checks simply have nothing
     * to compare against in that case and are skipped.
     */
    private static function isUsableTitle(string $title, ?Lead $lead): bool
    {
        if ($title === '' || strtoupper($title) === 'NONE') {
            return false;
        }

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH || mb_strlen($title) < 3) {
            return false;
        }

        // A free-model fallback chain can land on a model tuned for
        // something other than following this prompt — observed in
        // practice: an OpenRouter auto-router pick answered "User Safety:
        // safe" (a content-moderation classifier's own output format), which
        // isGroundedIn()/describesSalesProcessOnly() don't catch because
        // askModelRelaxed() (the pass that hit this) doesn't run those at
        // all. A colon this far into the string is a label/classification
        // shape ("Category: value"), never how a real project name reads —
        // clean() already stripped a genuine leading "Project Title:"
        // prefix, so anything left is a false answer, not formatting.
        if (str_contains($title, ':')) {
            return false;
        }

        // The prompt asks for 2-6 words and the model sometimes returns a
        // run-on instead ("Clothing E-Commerce Website Online Payment Admin
        // Panel"). Capped a little above the asked-for range rather than at
        // it: a 7-word title is still usable and the user can trim it, but
        // past that it has stopped being a title.
        if (str_word_count($title) > self::MAX_TITLE_WORDS) {
            return false;
        }

        $normalized = mb_strtolower($title);

        if (in_array($normalized, self::GENERIC_TITLES, true)) {
            return false;
        }

        if ($lead) {
            // The model was told not to use the lead's identity; a title that is
            // just their name or company carries no requirement at all.
            foreach ([$lead->name, $lead->company_name] as $identity) {
                $identity = mb_strtolower(trim((string) $identity));
                if ($identity !== '' && $normalized === $identity) {
                    return false;
                }
            }

            // Equality alone is not a sufficient test, though. The failure this
            // feature exists to prevent is "John Smith Website" — the lead's name
            // with a product noun stapled on. That survives the check above, and it
            // survives isGroundedIn() too, because the lead genuinely did say both
            // "John" and "website" somewhere in the chat. So ANY word of the lead's
            // personal name appearing anywhere in the title disqualifies it: the
            // title has to describe what is being built, never who is buying it.
            //
            // Company name is matched on equality only, deliberately. Companies are
            // often named for what they sell ("Karachi Property Group"), so their
            // words can legitimately be the project's words as well, and
            // token-matching those would throw away correct titles.
            if (array_intersect(
                self::normalizeWords((string) $lead->name),
                self::normalizeWords($title)
            ) !== []) {
                return false;
            }
        }

        // A refusal rendered as a sentence rather than the literal NONE.
        if (preg_match('/\b(insufficient|not enough|cannot determine|unclear|no information)\b/i', $title)) {
            return false;
        }

        return true;
    }
}
