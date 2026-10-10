<?php

namespace App\Http\Controllers\Api\Client;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ChatThread;
use App\Models\Client;
use App\Models\ClientAssistantMessage;
use App\Models\Invoice;
use App\Models\PaymentAssistantSession;
use App\Models\Project;
use App\Models\User;
use App\Services\ClientAssistant\AssistantIntent;
use App\Services\ClientAssistant\AssistantTools;
use App\Services\ClientAssistant\AssistantVoice;
use App\Services\ClientPaymentMethodService;
use App\Services\ClientSellerChatService;
use App\Services\PaymentAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The Client Portal's floating assistant widget.
 *
 * This is a SECOND FRONT DOOR onto machinery that already exists, not a second
 * implementation of it. Specifically:
 *
 *   - Flow state (which company, which invoice, where the payment got to) is
 *     the existing PaymentAssistantSession row. The widget and the full-page
 *     /client/payment-assistant screen therefore share one state, which is the
 *     only way they can never disagree about which invoice is in view.
 *   - Invoice matching, payable-status rules and amount-due arithmetic are
 *     PaymentAssistantService, unchanged.
 *   - Saved cards and the charge itself are ClientPaymentMethodService, which
 *     settles through InvoicePaymentService exactly as the public invoice link
 *     and the gateway webhook already do. There is still exactly one payment
 *     path in this app.
 *   - Adding a card is the existing POST /client/payment-methods/setup hosted
 *     flow; this controller only ever tells the browser to start it.
 *   - Reaching a seller is the existing per-project Client <-> Seller chat, or
 *     the existing NotificationService. No new conversation type.
 *
 * What is new is the routing: AssistantIntent decides WHICH flow the client
 * meant, AssistantTools decides what they are ALLOWED to see, and those two are
 * kept strictly apart. The model never receives client data, never resolves an
 * id, and never decides access — every id in every request body is re-resolved
 * against $request->user() before it is used, so a crafted payload can reach
 * nothing the client could not already reach through the portal's own screens.
 */
class AssistantController extends Controller
{
    /** The six chips, and the only set of them. */
    public const DEFAULT_SUGGESTIONS = [
        'Pay an Invoice',
        'Project Status',
        'Talk to My Seller',
        'Invoices Paid & Due',
        'Add / Manage Payment Method',
        'Something Else',
    ];

    /**
     * The assistant's name, as the client sees it.
     *
     * Single source of truth for both the refusal below and the answer to
     * "what are you called?" — the widget header reads the same words, so
     * renaming the assistant is one constant and one string in the UI.
     */
    public const ASSISTANT_NAME = 'AI Assistance';

    /** One wording for every out-of-scope request, per the brief. */
    private const OUT_OF_SCOPE_MESSAGE = "I'm " . self::ASSISTANT_NAME . ". I'm designed to help you with the options available here, so I can only assist with those supported options.";

    /**
     * The answer to "what is your name?", and nothing else.
     *
     * Deliberately one short sentence per language, hardcoded rather than
     * composed: the brief is explicit that this must not turn into "I'm AI
     * Assistance, your Client Portal assistant, I can help with…". It is also
     * the one reply that skips the AssistantVoice rewrite, because a
     * translator given a four-word sentence has nothing to do but add to it.
     */
    private const NAME_ANSWERS = [
        AssistantVoice::EN       => "I'm " . self::ASSISTANT_NAME . '.',
        AssistantVoice::ROMAN_UR => 'Main ' . self::ASSISTANT_NAME . ' hoon.',
        AssistantVoice::URDU     => 'میں ' . self::ASSISTANT_NAME . ' ہوں۔',
    ];

    // ── Session ──────────────────────────────────────────────────────────────

    /**
     * The shared assistant session for this login, created on first use.
     *
     * Same row, same auto-select-a-single-company rule as
     * PaymentAssistantController::session() — deliberately, so opening the
     * widget after using the page (or the other way round) continues the same
     * conversation rather than starting a competing one.
     */
    private function session(Request $request): PaymentAssistantSession
    {
        $session = PaymentAssistantSession::firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'state'                   => PaymentAssistantSession::COMPANY_SELECTION,
                'conversation_id'         => (string) Str::uuid(),
                'conversation_started_at' => now(),
            ]
        );

        $session->ensureConversation();

        if (!$session->company_id) {
            $companies = AssistantTools::companiesFor($request->user(), 'invoices');

            if (count($companies) === 1) {
                $session->update([
                    'company_id' => $companies[0]['company_id'],
                    'client_id'  => $companies[0]['client_id'],
                    'state'      => PaymentAssistantSession::WAITING_FOR_INVOICE,
                ]);
            }
        }

        return $session;
    }

    // ── Response shape ───────────────────────────────────────────────────────

    /**
     * Build, persist and return one assistant turn.
     *
     * The suggestions/actions are stored alongside the text so a reloaded
     * thread comes back with its buttons still attached — a transcript of
     * "Please confirm the payment" with no Confirm button is worse than no
     * transcript at all.
     */
    private function reply(
        Request $request,
        PaymentAssistantSession $session,
        string $message,
        array $suggestions = [],
        array $actions = [],
        array $extra = [],
        bool $translate = true
    ): JsonResponse {
        $language = $request->attributes->get('assistant_language', AssistantVoice::EN);

        if ($translate && $language !== AssistantVoice::EN) {
            $message = AssistantVoice::phrase($message, $language);
        }

        $payload = array_merge([
            'message'         => $message,
            'suggestions'     => $suggestions,
            'actions'         => $actions,
            'conversation_id' => $session->conversation_id,
        ], $extra);

        ClientAssistantMessage::record(
            (int) $request->user()->id,
            $session->conversation_id,
            'assistant',
            $message,
            array_diff_key($payload, ['message' => null, 'conversation_id' => null])
        );

        return ApiResponse::success($payload);
    }

    /** A transactional button. Suggestions are plain strings; these are not. */
    private function button(string $label, string $action, array $payload = []): array
    {
        return [
            'type'    => 'button',
            'label'   => $label,
            'action'  => $action,
            'payload' => $payload,
        ];
    }

    /** Standard "flow finished" tail — the six chips, no buttons. */
    private function done(Request $request, PaymentAssistantSession $session, string $message, array $extra = []): JsonResponse
    {
        return $this->reply($request, $session, $message, self::DEFAULT_SUGGESTIONS, [], $extra);
    }

    // ── GET /client/assistant ────────────────────────────────────────────────

    /**
     * Open the widget.
     *
     * A conversation already in progress comes back as it was; a fresh one gets
     * the opening line and the six chips. Either way this is a read — opening
     * the widget must not disturb a payment the client is halfway through.
     */
    public function open(Request $request): JsonResponse
    {
        $session = $this->session($request);

        $thread = ClientAssistantMessage::thread((int) $request->user()->id, $session->conversation_id);

        if ($thread->isNotEmpty()) {
            return ApiResponse::success([
                'conversation_id' => $session->conversation_id,
                'resumed'         => true,
                'transcript'      => $thread->map(fn (ClientAssistantMessage $m) => [
                    'id'      => $m->id,
                    'role'    => $m->role,
                    'content' => $m->content,
                    'payload' => $m->payload,
                ])->values()->all(),
            ]);
        }

        PaymentAssistantService::audit($session, 'widget_opened');

        return $this->reply(
            $request,
            $session,
            "What's on your mind?",
            self::DEFAULT_SUGGESTIONS,
            [],
            ['resumed' => false, 'transcript' => []]
        );
    }

    // ── POST /client/assistant/clear ─────────────────────────────────────────

    /**
     * Start a fresh conversation. Deletes nothing.
     *
     * Rotates the session's conversation_id exactly as the full-page
     * assistant's "Clear History" does, so past messages and the permanent
     * audit trail stay where they are and stay attributable — the client simply
     * gets an empty thread.
     */
    public function clear(Request $request): JsonResponse
    {
        $session = $this->session($request);

        PaymentAssistantService::audit($session, 'widget_history_cleared');
        $session->startConversation();
        $session->refresh();

        return $this->reply(
            $request,
            $session,
            "What's on your mind?",
            self::DEFAULT_SUGGESTIONS,
            [],
            ['cleared' => true, 'transcript' => []]
        );
    }

    // ── POST /client/assistant/message ───────────────────────────────────────

    public function message(Request $request): JsonResponse
    {
        $validated = $request->validate(['text' => ['required', 'string', 'max:500']]);

        $session = $this->session($request);
        $text    = trim($validated['text']);

        // Set before any reply is composed: every message this turn produces
        // comes back in the language the client just used.
        $request->attributes->set('assistant_language', AssistantVoice::detect($text));

        ClientAssistantMessage::record((int) $request->user()->id, $session->conversation_id, 'client', $text);

        $history = ClientAssistantMessage::thread((int) $request->user()->id, $session->conversation_id)
            ->reverse()
            ->skip(1)
            ->take(ClientAssistantMessage::AI_CONTEXT_TURNS)
            ->reverse()
            ->map(fn (ClientAssistantMessage $m) => [
                'role'    => $m->role === 'client' ? 'user' : 'assistant',
                'content' => $m->content,
            ])
            ->values()
            ->all();

        $intent = AssistantIntent::classify($text, $history);

        return match ($intent['intent']) {
            AssistantIntent::PAY_INVOICE     => $this->flowPayInvoice($request, $session, $intent['invoice_reference']),
            AssistantIntent::INVOICE_SUMMARY => $this->flowInvoiceSummary($request, $session),
            AssistantIntent::PROJECT_STATUS  => $this->flowProjects($request, $session, $intent['project_name']),
            AssistantIntent::TALK_TO_SELLER  => $this->flowSeller($request, $session),
            AssistantIntent::PAYMENT_METHODS => $this->flowPaymentMethods($request, $session),
            AssistantIntent::GREETING        => $this->done($request, $session, 'Hello! What can I help you with today — an invoice, a project update, or getting in touch with your seller?'),
            AssistantIntent::FAREWELL        => $this->done($request, $session, "Of course — if you need anything with an invoice, a project, a payment or your seller, just open the chat again. Take care!"),
            AssistantIntent::NAME_QUESTION   => $this->answerName($request, $session),
            AssistantIntent::SOMETHING_ELSE  => $this->done($request, $session, 'Sure — what do you need help with?'),
            default                          => $this->done($request, $session, self::OUT_OF_SCOPE_MESSAGE),
        };
    }

    /**
     * "What is your name?" — the name, in the client's language, full stop.
     *
     * Not routed through the generic out-of-scope refusal (a question about the
     * assistant is not an unrelated question) and not routed through the
     * translator either — the three wordings are fixed, so there is no room for
     * a model to turn four words into a sales pitch.
     */
    private function answerName(Request $request, PaymentAssistantSession $session): JsonResponse
    {
        $language = $request->attributes->get('assistant_language', AssistantVoice::EN);

        return $this->reply(
            $request,
            $session,
            self::NAME_ANSWERS[$language] ?? self::NAME_ANSWERS[AssistantVoice::EN],
            self::DEFAULT_SUGGESTIONS,
            [],
            [],
            // Already in the client's language — nothing to translate.
            false
        );
    }

    // ── POST /client/assistant/action ────────────────────────────────────────

    /**
     * A button press.
     *
     * Every payload value here is a HINT about which branch to run, never a
     * fact. Each handler re-resolves its invoice, card, project or company
     * against the authenticated login before touching anything, so a forged
     * payload can only fail to resolve.
     */
    public function action(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'action'  => ['required', 'string', 'max:40'],
            'payload' => ['sometimes', 'array'],
            'label'   => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $session = $this->session($request);
        $payload = $validated['payload'] ?? [];

        // The button's own label is what the client sees themselves "say", so
        // it is what goes into the transcript.
        if (!empty($validated['label'])) {
            ClientAssistantMessage::record(
                (int) $request->user()->id,
                $session->conversation_id,
                'client',
                $validated['label']
            );
        }

        $intId = fn (string $key) => isset($payload[$key]) && is_numeric($payload[$key]) ? (int) $payload[$key] : null;

        return match ($validated['action']) {
            'select_company'     => $this->actionSelectCompany($request, $session, $intId('company_id'), (string) ($payload['then'] ?? AssistantIntent::PAY_INVOICE)),
            'select_invoice'     => $this->showInvoice($request, $session, $intId('invoice_id')),
            'latest_unpaid'      => $this->flowPayInvoice($request, $session, null, true),
            'show_due_invoices'  => $this->flowInvoiceSummary($request, $session),
            'pay_invoice'        => $this->actionPayNow($request, $session, $intId('invoice_id')),
            'select_card'        => $this->actionSelectCard($request, $session, $intId('invoice_id'), $intId('payment_method_id')),
            'confirm_payment'    => $this->actionConfirmPayment($request, $session, $intId('invoice_id'), $intId('payment_method_id')),
            'start_card_setup'   => $this->actionStartCardSetup($request, $session, $intId('company_id')),
            'select_project'     => $this->actionSelectProject($request, $session, $intId('project_id')),
            'select_project_seller' => $this->actionSelectProjectSeller($request, $session, $intId('project_id')),
            'open_project_chat'  => $this->actionOpenProjectChat($request, $session, $intId('project_id')),
            'open_seller_chat'   => $this->actionOpenSellerChat($request, $session, $intId('thread_id')),
            'contact_support'    => $this->done($request, $session, "You can raise this with our support team from the Support section of the portal, and someone will pick it up there."),
            'cancel'             => $this->done($request, $session, 'No problem, let me know if you change your mind.'),
            default              => $this->done($request, $session, self::OUT_OF_SCOPE_MESSAGE),
        };
    }

    // ── Company resolution ───────────────────────────────────────────────────

    /**
     * Which company this turn is about, for a given module.
     *
     * Returns either a resolved Client row, or the reply that asks the client
     * to choose one. Never guesses when there is more than one: a client at two
     * companies who is shown the wrong company's invoices has been shown the
     * wrong data, even though both are theirs.
     *
     * @return array{client: Client|null, reply: JsonResponse|null}
     */
    private function resolveClient(
        Request $request,
        PaymentAssistantSession $session,
        string $moduleKey,
        string $then,
        string $question
    ): array {
        $companies = AssistantTools::companiesFor($request->user(), $moduleKey);

        if (count($companies) === 0) {
            return [
                'client' => null,
                'reply'  => $this->done(
                    $request,
                    $session,
                    'That part of the portal is not enabled for your account at the moment. Your seller can switch it on for you.'
                ),
            ];
        }

        if (count($companies) === 1) {
            $client = AssistantTools::clientAt($request->user(), (int) $companies[0]['company_id'], $moduleKey);

            return ['client' => $client, 'reply' => null];
        }

        // The session may already be pinned to one of them from an earlier turn
        // (or from the full-page assistant), in which case there is nothing to
        // ask — but only if it is still a valid choice for THIS module.
        if ($session->company_id) {
            $client = AssistantTools::clientAt($request->user(), (int) $session->company_id, $moduleKey);

            if ($client) {
                return ['client' => $client, 'reply' => null];
            }
        }

        $actions = array_map(
            fn (array $c) => $this->button(
                $c['company_name'] ?: "Company {$c['company_id']}",
                'select_company',
                ['company_id' => $c['company_id'], 'then' => $then]
            ),
            $companies
        );

        // Mid-flow: only the company buttons, never the six chips.
        return [
            'client' => null,
            'reply'  => $this->reply($request, $session, $question, [], $actions),
        ];
    }

    private function actionSelectCompany(Request $request, PaymentAssistantSession $session, ?int $companyId, string $then): JsonResponse
    {
        if (!$companyId) {
            return $this->done($request, $session, 'I could not tell which company that was. Which one is this about?');
        }

        // Validated against the login's own companies, not accepted as given.
        $client = AssistantTools::portalClients($request->user())->firstWhere('company_id', $companyId);

        if (!$client) {
            return $this->done($request, $session, 'That company is not available for your account.');
        }

        $session->update([
            'company_id' => (int) $client->company_id,
            'client_id'  => (int) $client->id,
            'invoice_id' => null,
            'state'      => PaymentAssistantSession::WAITING_FOR_INVOICE,
        ]);
        PaymentAssistantService::audit($session, 'widget_company_selected');

        return match ($then) {
            AssistantIntent::INVOICE_SUMMARY => $this->flowInvoiceSummary($request, $session),
            AssistantIntent::PROJECT_STATUS  => $this->flowProjects($request, $session, null),
            AssistantIntent::TALK_TO_SELLER  => $this->flowSeller($request, $session),
            AssistantIntent::PAYMENT_METHODS => $this->flowPaymentMethods($request, $session),
            default                          => $this->flowPayInvoice($request, $session, null),
        };
    }

    // ── FLOW A — Pay an invoice ──────────────────────────────────────────────

    private function flowPayInvoice(
        Request $request,
        PaymentAssistantSession $session,
        ?string $reference,
        bool $useLatestUnpaid = false
    ): JsonResponse {
        ['client' => $client, 'reply' => $ask] = $this->resolveClient(
            $request,
            $session,
            'invoices',
            AssistantIntent::PAY_INVOICE,
            'Which company is this invoice for?'
        );

        if ($ask) {
            return $ask;
        }

        if ($useLatestUnpaid) {
            $invoice = AssistantTools::latestUnpaidInvoice($request->user(), (int) $client->company_id);

            return $invoice
                ? $this->presentInvoice($request, $session, $invoice)
                : $this->done($request, $session, 'Good news — you have no outstanding invoices with us right now.');
        }

        if (!$reference) {
            return $this->reply(
                $request,
                $session,
                'Sure — which invoice would you like to pay? You can give me the invoice number, or I can pull up your most recent one.',
                [],
                [
                    $this->button('Use my most recent unpaid invoice', 'latest_unpaid'),
                    $this->button('Show my due invoices', 'show_due_invoices'),
                ]
            );
        }

        PaymentAssistantService::audit($session, 'widget_invoice_searched', null, ['query' => $reference]);

        $invoice = AssistantTools::findInvoice($request->user(), $reference, (int) $client->company_id);

        if (!$invoice) {
            // Says only that it was not found for THIS account — never whether
            // the number exists somewhere else, which would leak another
            // client's invoicing.
            return $this->reply(
                $request,
                $session,
                "I couldn't find that invoice in your account. Please check the invoice number, or I can show you what's currently due.",
                [],
                [$this->button('Show my due invoices', 'show_due_invoices')]
            );
        }

        return $this->presentInvoice($request, $session, $invoice);
    }

    /** The invoice card, plus Pay Now / Cancel when there is actually something to pay. */
    private function presentInvoice(Request $request, PaymentAssistantSession $session, Invoice $invoice): JsonResponse
    {
        $session->update([
            'invoice_id' => $invoice->id,
            'state'      => PaymentAssistantSession::INVOICE_FOUND,
        ]);
        PaymentAssistantService::audit($session, 'widget_invoice_selected', $invoice->id);

        $card = PaymentAssistantService::invoiceCard($invoice);
        $money = fn (float $n) => trim(($card['currency'] ? $card['currency'] . ' ' : '') . number_format($n, 2));

        if (!PaymentAssistantService::isPayable($invoice)) {
            $already = $invoice->status === 'paid' || $card['amount_due'] <= 0;

            return $this->done(
                $request,
                $session,
                $already
                    ? "Invoice #{$card['invoice_number']} has already been paid, so there's nothing outstanding on it. Did you mean a different invoice?"
                    : "Invoice #{$card['invoice_number']} isn't available for payment right now. Your seller can help with that one.",
                ['invoice' => $card]
            );
        }

        $due = $card['due_date'] ? ", due {$card['due_date']}" : '';

        return $this->reply(
            $request,
            $session,
            "I found invoice #{$card['invoice_number']} for {$money($card['amount_due'])}{$due}. It's still outstanding — would you like to pay it now?",
            [],
            [
                $this->button('Pay Now', 'pay_invoice', ['invoice_id' => $card['id']]),
                $this->button('Cancel', 'cancel'),
            ],
            ['invoice' => $card]
        );
    }

    private function showInvoice(Request $request, PaymentAssistantSession $session, ?int $invoiceId): JsonResponse
    {
        $invoice = $invoiceId ? AssistantTools::invoiceById($request->user(), $invoiceId) : null;

        return $invoice
            ? $this->presentInvoice($request, $session, $invoice)
            : $this->done($request, $session, 'That invoice is not available for your account.');
    }

    /**
     * Pay Now — offers a card. Charges nothing.
     */
    private function actionPayNow(Request $request, PaymentAssistantSession $session, ?int $invoiceId): JsonResponse
    {
        $invoice = $invoiceId ? AssistantTools::invoiceById($request->user(), $invoiceId) : null;

        if (!$invoice) {
            return $this->done($request, $session, 'That invoice is not available for your account.');
        }

        // Re-read rather than trusting what the card said a moment ago: it may
        // have been settled from the public link or by a recorded bank transfer
        // since this message was rendered.
        if (!PaymentAssistantService::isPayable($invoice)) {
            return $this->done(
                $request,
                $session,
                "Invoice #{$invoice->invoice_number} has already been settled, so there's nothing to pay. Nothing has been charged."
            );
        }

        $client = AssistantTools::clientsFor($request->user(), 'invoices')
            ->firstWhere('id', (int) $invoice->client_id);

        if (!$client) {
            return $this->done($request, $session, 'That invoice is not available for your account.');
        }

        $session->update([
            'invoice_id' => $invoice->id,
            'company_id' => (int) $client->company_id,
            'client_id'  => (int) $client->id,
            'state'      => PaymentAssistantSession::CONFIRMING,
        ]);
        PaymentAssistantService::audit($session, 'widget_payment_initiated', $invoice->id);

        $methods = AssistantTools::savedMethods($client);

        if (!$methods) {
            if (!AssistantTools::canSaveCards($client)) {
                // This company's gateway cannot vault a card, so the widget has
                // nothing to offer — the ordinary hosted checkout on the invoice
                // page is the path, and sending them there beats a dead end.
                return $this->done(
                    $request,
                    $session,
                    "To pay invoice #{$invoice->invoice_number}, open it from your Invoices page and choose a payment option there — that takes you straight to the secure payment page."
                );
            }

            return $this->reply(
                $request,
                $session,
                "You don't have a card saved yet. I'll open our secure payment page so you can add one — your card details are entered there, never here in the chat.",
                [],
                [
                    $this->button('Add a card securely', 'start_card_setup', ['company_id' => (int) $client->company_id]),
                    $this->button('Cancel', 'cancel'),
                ]
            );
        }

        $actions = array_map(
            fn (array $m) => $this->button($m['label'], 'select_card', [
                'invoice_id'        => $invoice->id,
                'payment_method_id' => $m['id'],
            ]),
            $methods
        );

        $actions[] = $this->button('Add a new card', 'start_card_setup', ['company_id' => (int) $client->company_id]);
        $actions[] = $this->button('Cancel', 'cancel');

        return $this->reply($request, $session, 'Which card would you like to pay with?', [], $actions);
    }

    /** Card chosen — the last stop before money moves. Still not a charge. */
    private function actionSelectCard(Request $request, PaymentAssistantSession $session, ?int $invoiceId, ?int $methodId): JsonResponse
    {
        [$invoice, $client, $method, $error] = $this->resolvePaymentTriple($request, $invoiceId, $methodId);

        if ($error) {
            return $this->done($request, $session, $error);
        }

        $amount = PaymentAssistantService::amountDue($invoice);
        $money  = trim(($invoice->currency ? $invoice->currency . ' ' : '') . number_format($amount, 2));

        return $this->reply(
            $request,
            $session,
            "You're about to pay {$money} for invoice #{$invoice->invoice_number} using {$method->label()}. Please confirm to continue.",
            [],
            [
                $this->button('Confirm Payment', 'confirm_payment', [
                    'invoice_id'        => $invoice->id,
                    'payment_method_id' => $method->id,
                ]),
                $this->button('Cancel', 'cancel'),
            ]
        );
    }

    /**
     * The one request in this controller that moves money.
     *
     * Everything is re-derived here: the invoice from the id (re-authorized),
     * the amount from the invoice, the card from this client's own saved
     * methods at this company. The charge itself is
     * ClientPaymentMethodService::charge(), which takes a row lock on the
     * invoice and settles through InvoicePaymentService — so duplicate
     * protection, receipts, notifications and automatic project creation are
     * the existing behaviour, not a second copy of it.
     */
    private function actionConfirmPayment(Request $request, PaymentAssistantSession $session, ?int $invoiceId, ?int $methodId): JsonResponse
    {
        [$invoice, $client, $method, $error] = $this->resolvePaymentTriple($request, $invoiceId, $methodId);

        if ($error) {
            return $this->done($request, $session, $error);
        }

        $session->update([
            'invoice_id' => $invoice->id,
            'company_id' => (int) $client->company_id,
            'client_id'  => (int) $client->id,
            'state'      => PaymentAssistantSession::PROCESSING,
        ]);

        [$ok, $message] = ClientPaymentMethodService::charge($invoice, $method);

        $session->update([
            'state' => $ok ? PaymentAssistantSession::PAYMENT_SUCCESS : PaymentAssistantSession::PAYMENT_FAILED,
        ]);
        PaymentAssistantService::audit(
            $session,
            $ok ? 'widget_payment_success' : 'widget_payment_failure',
            $invoice->id
        );

        $fresh = $invoice->fresh();

        if (!$ok) {
            // $message is the service's own client-safe wording — it never
            // carries a processor code, and the raw gateway error was logged
            // internally rather than shown.
            return $this->reply(
                $request,
                $session,
                $message ?: 'The payment could not be completed. You can try again or choose another payment method.',
                [],
                [
                    $this->button('Try another card', 'pay_invoice', ['invoice_id' => $invoice->id]),
                    $this->button('Cancel', 'cancel'),
                ]
            );
        }

        $paid  = round((float) $invoice->total_amount - (float) $invoice->paid_amount, 2);
        $money = trim(($invoice->currency ? $invoice->currency . ' ' : '') . number_format(max($paid, 0), 2));

        return $this->done(
            $request,
            $session,
            "Payment successful — {$money} paid for invoice #{$invoice->invoice_number}. It's now marked as {$fresh->status}.",
            ['invoice' => PaymentAssistantService::invoiceCard($fresh), 'paid' => true]
        );
    }

    /**
     * Invoice + client + card, each re-resolved against this login, or the
     * message explaining which one did not.
     *
     * @return array{0: Invoice|null, 1: Client|null, 2: \App\Models\ClientPaymentMethod|null, 3: string|null}
     */
    private function resolvePaymentTriple(Request $request, ?int $invoiceId, ?int $methodId): array
    {
        $invoice = $invoiceId ? AssistantTools::invoiceById($request->user(), $invoiceId) : null;

        if (!$invoice) {
            return [null, null, null, 'That invoice is not available for your account.'];
        }

        if (!PaymentAssistantService::isPayable($invoice)) {
            return [null, null, null, "Invoice #{$invoice->invoice_number} has already been settled, so nothing has been charged."];
        }

        $client = AssistantTools::clientsFor($request->user(), 'invoices')
            ->firstWhere('id', (int) $invoice->client_id);

        if (!$client) {
            return [null, null, null, 'That invoice is not available for your account.'];
        }

        // The card must belong to this client AND this company — never looked
        // up by id alone, which would let a crafted payload reach someone
        // else's saved card.
        $method = $methodId ? AssistantTools::savedMethodById($client, $methodId) : null;

        if (!$method) {
            return [null, null, null, 'That payment method is not available for your account.'];
        }

        return [$invoice, $client, $method, null];
    }

    /**
     * Hand the browser to the existing hosted card-setup flow.
     *
     * No card detail passes through here, and this controller does not build
     * the checkout itself — the frontend calls the existing
     * POST /client/payment-methods/setup endpoint with the company id this
     * returns, so per-company gateway accounts and the return/cancel URLs stay
     * in the one place that already owns them.
     */
    private function actionStartCardSetup(Request $request, PaymentAssistantSession $session, ?int $companyId): JsonResponse
    {
        $client = $companyId ? AssistantTools::clientAt($request->user(), $companyId, 'invoices') : null;

        if (!$client) {
            return $this->done($request, $session, 'That company is not available for your account.');
        }

        if (!AssistantTools::canSaveCards($client)) {
            return $this->done(
                $request,
                $session,
                'Saving a card is not available for this account yet. You can still pay any invoice from your Invoices page.'
            );
        }

        // No button on this turn: the instruction below is what the widget acts
        // on, immediately. A button that re-runs this same action would loop.
        //
        // Inline when the gateway can do it — the card fields open inside the
        // chat, in Stripe's own iframe, and the client never leaves the portal.
        // Only a gateway that cannot falls back to the hosted page, which is
        // what every company had before.
        if (ClientPaymentMethodService::supportsInlineSetup((int) $client->company_id, 'stripe')) {
            return $this->reply(
                $request,
                $session,
                "Let's add your card. It's entered securely below and handled by our payment provider — nothing is charged for adding it, and your card number never passes through this chat.",
                [],
                [],
                ['open_card_form' => ['company_id' => (int) $client->company_id]]
            );
        }

        return $this->reply(
            $request,
            $session,
            "I'm opening our secure payment page so you can add your card. Nothing is charged while you add it, and your card details never come through this chat.",
            [],
            [],
            ['open_card_setup' => ['company_id' => (int) $client->company_id]]
        );
    }

    // ── FLOW D — Invoices paid & due ─────────────────────────────────────────

    private function flowInvoiceSummary(Request $request, PaymentAssistantSession $session): JsonResponse
    {
        ['client' => $client, 'reply' => $ask] = $this->resolveClient(
            $request,
            $session,
            'invoices',
            AssistantIntent::INVOICE_SUMMARY,
            'Which company would you like the invoice summary for?'
        );

        if ($ask) {
            return $ask;
        }

        $summary = AssistantTools::invoiceSummary($client);
        $ccy     = $summary['currency'] ? $summary['currency'] . ' ' : '';

        if ($summary['due_count'] === 0) {
            return $this->done(
                $request,
                $session,
                $summary['paid_count'] === 0
                    ? "You don't have any invoices on your account yet."
                    : "You've paid {$summary['paid_count']} " . Str::plural('invoice', $summary['paid_count']) . ", and nothing is outstanding right now.",
                ['summary' => $summary]
            );
        }

        $lines = array_map(
            fn (array $i) => "#{$i['invoice_number']} — {$ccy}" . number_format($i['amount_due'], 2)
                . ($i['due_date'] ? " — due {$i['due_date']}" : ''),
            $summary['due']
        );

        $more = $summary['due_count'] > count($summary['due'])
            ? ' (showing the ' . count($summary['due']) . ' soonest)'
            : '';

        $message = "You've paid {$summary['paid_count']} " . Str::plural('invoice', $summary['paid_count'])
            . ", and {$summary['due_count']} " . Str::plural('invoice', $summary['due_count'])
            . ' ' . ($summary['due_count'] === 1 ? 'is' : 'are') . " still due — {$ccy}"
            . number_format($summary['total_due'], 2) . " in total{$more}.\n\n"
            . implode("\n", $lines)
            . "\n\nTap one to pay it.";

        $actions = array_map(
            fn (array $i) => $this->button(
                "#{$i['invoice_number']} · {$ccy}" . number_format($i['amount_due'], 2),
                'select_invoice',
                ['invoice_id' => $i['id']]
            ),
            $summary['due']
        );

        return $this->reply($request, $session, $message, [], $actions, ['summary' => $summary]);
    }

    // ── FLOW B — Project status ──────────────────────────────────────────────

    private function flowProjects(Request $request, PaymentAssistantSession $session, ?string $projectName): JsonResponse
    {
        ['client' => $client, 'reply' => $ask] = $this->resolveClient(
            $request,
            $session,
            'projects',
            AssistantIntent::PROJECT_STATUS,
            'Which company are you asking about?'
        );

        if ($ask) {
            return $ask;
        }

        // A named project answers directly rather than making the client pick
        // from a list they already told us the answer to.
        if ($projectName) {
            $named = AssistantTools::findProjectByName($request->user(), $projectName, (int) $client->company_id);

            if ($named) {
                return $this->presentProject($request, $session, $named);
            }
        }

        $projects = AssistantTools::projects($request->user(), (int) $client->company_id);

        if ($projects->isEmpty()) {
            return $this->done($request, $session, "You don't have any active projects with us at the moment.");
        }

        $s = AssistantTools::projectSummary($projects);

        $message = "You currently have {$s['total']} " . Str::plural('project', $s['total']) . ' — '
            . "{$s['ongoing']} " . ($s['ongoing'] === 1 ? 'is' : 'are') . ' in progress and '
            . "{$s['completed']} " . ($s['completed'] === 1 ? 'is' : 'are') . ' completed.';

        if ($s['next_deadline']) {
            $message .= " The next deadline is \"{$s['next_deadline']->name}\" on "
                . $s['next_deadline']->deadline->toDateString() . '.';
        }

        if ($projects->count() > 1) {
            $message .= "\n\nPick one for more detail.";
        }

        $actions = $projects->take(8)
            ->map(fn (Project $p) => $this->button($p->name, 'select_project', ['project_id' => $p->id]))
            ->values()
            ->all();

        return $projects->count() === 1
            ? $this->presentProject($request, $session, $projects->first())
            : $this->reply($request, $session, $message, [], $actions);
    }

    private function actionSelectProject(Request $request, PaymentAssistantSession $session, ?int $projectId): JsonResponse
    {
        $project = $projectId ? AssistantTools::projectById($request->user(), $projectId) : null;

        return $project
            ? $this->presentProject($request, $session, $project)
            : $this->done($request, $session, 'That project is not available for your account.');
    }

    /** One project, described only from what the row actually holds. */
    private function presentProject(Request $request, PaymentAssistantSession $session, Project $project): JsonResponse
    {
        $card = AssistantTools::projectCard($project);

        $status = str_replace('_', ' ', (string) $card['status']);
        $parts  = ["\"{$card['name']}\" is currently {$status}, at {$card['progress']}% complete"];

        if ($card['open_tasks'] > 0) {
            $parts[] = "with {$card['open_tasks']} " . Str::plural('task', $card['open_tasks']) . ' still open';
        }

        $message = implode(' ', $parts) . '.';

        if ($card['deadline']) {
            $message .= " The deadline on file is {$card['deadline']}.";
        }

        if ($card['manager']) {
            $message .= " {$card['manager']} is looking after it.";
        }

        return $this->done($request, $session, $message, ['project' => $card]);
    }

    // ── FLOW C — Talk to my seller ───────────────────────────────────────────

    private function flowSeller(Request $request, PaymentAssistantSession $session): JsonResponse
    {
        // Not module-gated: reaching the person who sold to you is not one of
        // the portal's switchable sections. The CONNECT branch below still
        // requires the projects module, because that is where the conversation
        // actually lives.
        $companies = AssistantTools::portalClients($request->user());

        if ($companies->isEmpty()) {
            return $this->done($request, $session, 'I could not find an account to look this up against.');
        }

        if ($companies->count() > 1 && !$session->company_id) {
            $actions = $companies->map(fn (Client $c) => $this->button(
                $c->company?->name ?: $c->company_name ?: "Company {$c->company_id}",
                'select_company',
                ['company_id' => (int) $c->company_id, 'then' => AssistantIntent::TALK_TO_SELLER]
            ))->values()->all();

            return $this->reply(
                $request,
                $session,
                'Which company would you like to contact your seller about?',
                [],
                $actions
            );
        }

        $client = $session->company_id
            ? $companies->firstWhere('company_id', (int) $session->company_id) ?? $companies->first()
            : $companies->first();

        // The conversation lives on a PROJECT (one project = one thread, see
        // ProjectChatService), so which project is the question that decides
        // which seller — a client with three projects may have three different
        // sellers, and picking "their seller" for them would put the message in
        // front of the wrong one.
        $projects = AssistantTools::projectsWithSeller($request->user(), (int) $client->company_id);

        if ($projects->count() === 1) {
            return $this->openProjectSellerChat($request, $session, $projects->first());
        }

        if ($projects->count() > 1) {
            $actions = $projects->take(8)
                ->map(fn (Project $p) => $this->button(
                    "{$p->name} · {$p->seller->name}",
                    'select_project_seller',
                    ['project_id' => (int) $p->id]
                ))
                ->values()
                ->all();

            return $this->reply(
                $request,
                $session,
                'Which project would you like to talk about?',
                [],
                $actions
            );
        }

        // No project to talk about — a standalone-invoice client, or one whose
        // work has not started yet. They still get their seller, on the
        // account-level Direct Chat that needs no project to exist.
        $seller = AssistantTools::assignedSeller($client);

        if (!$seller) {
            return $this->reply(
                $request,
                $session,
                "I couldn't find an assigned seller for this account. I can point you to support instead — would that help?",
                [],
                [
                    $this->button('Contact support', 'contact_support'),
                    $this->button('Cancel', 'cancel'),
                ]
            );
        }

        return $this->openSellerConversation($request, $session, $client, $seller['user']);
    }

    /**
     * A project picked from the list above.
     *
     * The id is a hint, never a fact: projectById() re-derives it from the
     * login's own Client rows (and skips drafts), so a forged or stale id can
     * only fail to resolve.
     */
    private function actionSelectProjectSeller(Request $request, PaymentAssistantSession $session, ?int $projectId): JsonResponse
    {
        $project = $projectId ? AssistantTools::projectById($request->user(), $projectId) : null;

        if (!$project) {
            return $this->done($request, $session, 'That project is not available for your account.');
        }

        return $this->openProjectSellerChat($request, $session, $project);
    }

    /**
     * Hand the client to that project's own conversation, and step out of the
     * way.
     *
     * Nothing is created here and no second conversation exists: the window
     * this opens reads and writes the project's EXISTING chat through
     * Api\Client\ProjectChatController — the same rows the seller sees on
     * /projects/{id}/chat and Company Admin sees on their own project page.
     * The assistant only says which project it is and gets out.
     *
     * The payload carries just enough for the window's header to paint
     * immediately; the window then loads the real thread (history included)
     * from the project chat endpoint, which is also what a notification
     * deep-link does with nothing but a project id.
     */
    private function openProjectSellerChat(
        Request $request,
        PaymentAssistantSession $session,
        Project $project
    ): JsonResponse {
        $seller = $project->seller_id
            ? User::where('id', $project->seller_id)->where('is_active', true)->first()
            : null;

        if (!$seller) {
            return $this->reply(
                $request,
                $session,
                "\"{$project->name}\" doesn't have a seller assigned at the moment. I can point you to support instead — would that help?",
                [],
                [
                    $this->button('Contact support', 'contact_support'),
                    $this->button('Cancel', 'cancel'),
                ]
            );
        }

        PaymentAssistantService::audit($session, 'widget_project_seller_chat_opened', null, [
            'project_id' => (int) $project->id,
            'seller_id'  => (int) $seller->id,
        ]);

        return $this->reply(
            $request,
            $session,
            "Here's your conversation with {$seller->name} about \"{$project->name}\".",
            [],
            [$this->button("Open chat with {$seller->name}", 'open_project_chat', ['project_id' => (int) $project->id])],
            ['open_project_chat' => [
                'project_id'   => (int) $project->id,
                'project_name' => $project->name,
                'company_name' => $project->company?->name,
                'seller'       => [
                    'user_id' => (int) $seller->id,
                    'name'    => $seller->name,
                    'role'    => $seller->custom_role_label ?: 'Seller',
                    'online'  => $seller->isOnline(),
                ],
            ]]
        );
    }

    /**
     * Reopen a project conversation from a button further up the transcript.
     *
     * Re-validated exactly as the first open was — the project must still be
     * one of this login's own — so an id from an old bubble can only fail.
     */
    private function actionOpenProjectChat(Request $request, PaymentAssistantSession $session, ?int $projectId): JsonResponse
    {
        $project = $projectId ? AssistantTools::projectById($request->user(), $projectId) : null;

        if (!$project) {
            return $this->done($request, $session, 'That conversation is not available for your account.');
        }

        return $this->openProjectSellerChat($request, $session, $project);
    }

    /**
     * The handoff itself: open (or reopen) the client's own conversation with
     * this seller, and step out of the way.
     *
     * This is where AI Assistance stops being the counterparty. From here the
     * thread is a plain Client <-> Seller conversation on the app's existing
     * per-client Direct Chat — the same rows the seller already sees on their
     * Client detail page — and no reply is ever generated, relayed or signed by
     * the assistant.
     *
     * Creating on this press IS the client initiating contact, which is what
     * makes the conversation appear for the seller at all. Reopening later
     * finds the same thread: no second conversation, no second welcome, no
     * second notification (see ClientSellerChatService).
     */
    private function openSellerConversation(
        Request $request,
        PaymentAssistantSession $session,
        Client $client,
        User $seller
    ): JsonResponse {
        $language = $request->attributes->get('assistant_language', AssistantVoice::EN);

        try {
            ['thread' => $thread, 'created' => $created] = ClientSellerChatService::startFromClient(
                $client,
                $seller,
                $language
            );
        } catch (\Throwable $e) {
            Log::warning('[client-assistant] seller handoff failed', [
                'client_id' => $client->id,
                'seller_id' => $seller->id,
                'error'     => $e->getMessage(),
            ]);

            return $this->done(
                $request,
                $session,
                "I couldn't open your conversation with {$seller->name} just now. Please try again in a moment, or raise it with support from the Support section."
            );
        }

        PaymentAssistantService::audit($session, $created ? 'widget_seller_chat_started' : 'widget_seller_chat_reopened', null, [
            'client_id' => (int) $client->id,
            'thread_id' => (int) $thread->id,
        ]);

        return $this->reply(
            $request,
            $session,
            $created
                ? "I'll connect you with {$seller->name}."
                : "You already have a conversation open with {$seller->name} — here it is.",
            [],
            [$this->button("Open chat with {$seller->name}", 'open_seller_chat', ['thread_id' => (int) $thread->id])],
            // The widget switches into the seller conversation on this.
            ['open_seller_chat' => [
                'thread_id' => (int) $thread->id,
                'name'      => $seller->name,
                'role'      => $seller->custom_role_label ?: 'Seller',
            ]]
        );
    }

    /**
     * Reopen a conversation from a button further up the transcript.
     *
     * The thread id is re-validated the same way the seller-chat endpoints
     * validate it — owned Client, same company, and this login actually a
     * participant — so an id from an old bubble (or a crafted one) can only
     * fail to resolve.
     */
    private function actionOpenSellerChat(Request $request, PaymentAssistantSession $session, ?int $threadId): JsonResponse
    {
        $clients = AssistantTools::portalClients($request->user());

        $thread = $threadId && $clients->isNotEmpty()
            ? ChatThread::where('id', $threadId)
                ->where('thread_type', 'client')
                ->where('linked_to_type', 'Client')
                ->whereIn('linked_to_id', $clients->pluck('id'))
                ->whereIn('company_id', $clients->pluck('company_id'))
                ->whereHas('participants', fn ($q) => $q->where('user_id', $request->user()->id))
                ->first()
            : null;

        if (!$thread) {
            return $this->done($request, $session, 'That conversation is not available for your account.');
        }

        return $this->reply(
            $request,
            $session,
            'Opening your conversation now.',
            [],
            [],
            ['open_seller_chat' => ['thread_id' => (int) $thread->id]]
        );
    }

    // ── Add / manage payment methods ─────────────────────────────────────────

    private function flowPaymentMethods(Request $request, PaymentAssistantSession $session): JsonResponse
    {
        ['client' => $client, 'reply' => $ask] = $this->resolveClient(
            $request,
            $session,
            'invoices',
            AssistantIntent::PAYMENT_METHODS,
            'Which company are these cards for?'
        );

        if ($ask) {
            return $ask;
        }

        $methods = AssistantTools::savedMethods($client);
        $canAdd  = AssistantTools::canSaveCards($client);

        if (!$methods) {
            return $canAdd
                ? $this->reply(
                    $request,
                    $session,
                    "You don't have any cards saved yet. I can open our secure payment page so you can add one — nothing is charged for adding a card.",
                    [],
                    [
                        $this->button('Add a card securely', 'start_card_setup', ['company_id' => (int) $client->company_id]),
                        $this->button('Cancel', 'cancel'),
                    ]
                )
                : $this->done(
                    $request,
                    $session,
                    'Saving a card is not available for this account yet. You can still pay any invoice from your Invoices page.'
                );
        }

        $labels = implode(', ', array_column($methods, 'label'));

        $actions = [];

        if ($canAdd) {
            $actions[] = $this->button('Add a new card', 'start_card_setup', ['company_id' => (int) $client->company_id]);
        }

        // Both, deliberately. Listing cards ANSWERS a question rather than
        // opening a flow, so the client is not waiting on a specific input and
        // the chips belong here — the Add button is simply the one extra thing
        // they might want next.
        return $this->reply(
            $request,
            $session,
            'You have ' . count($methods) . ' ' . Str::plural('card', count($methods))
                . " saved: {$labels}. You can manage them on the Payment Methods page, or add another here.",
            self::DEFAULT_SUGGESTIONS,
            $actions,
            ['payment_methods' => $methods]
        );
    }
}
