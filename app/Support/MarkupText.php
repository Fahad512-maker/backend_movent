<?php

namespace App\Support;

/**
 * Server-side reader for the lightweight markup the description editor
 * writes — Project Description and Task Description only (see
 * frontend/components/ui/RichTextField.tsx for the writer and
 * frontend/components/ui/RichText.tsx for the in-app renderer; keep the
 * supported markup in sync with those two).
 *
 * Only needed where a description is rendered OUTSIDE the React app — today
 * that's the project-activated email, which would otherwise show the raw
 * "**bold**" markers to a client.
 *
 * Nothing here trusts the input: every text fragment is escaped with
 * htmlspecialchars() and only a fixed tag whitelist (strong/em/ul/ol/li/p/a
 * plus a styled heading div) is ever emitted, with hrefs limited to
 * http/https/mailto. That's what makes it safe for a Blade template to print
 * the result with {!! !!}.
 */
class MarkupText
{
    /** Matches [label](url); one level of balanced parens allowed in the url. */
    private const NAMED_LINK = '/\[([^\]\n]+)\]\(((?:[^\s()]|\([^\s()]*\))+)\)/';
    private const AUTO_LINK  = '#(https?://[^\s<]+|www\.[^\s<]+|[\w.+\-]+@[\w\-]+\.[\w.\-]+)#';
    private const BOLD       = '/\*\*([^\n]+?)\*\*/';
    private const ITALIC     = '/(?:_([^_\n]+?)_|\*([^*\n]+?)\*)/';

    private const HEADING = '/^\s*(\#{1,3})\s+(.*)$/';
    private const BULLET  = '/^\s*[-*]\s+(.*)$/';
    private const NUMBER  = '/^\s*\d+[.)]\s+(.*)$/';

    private const LINK_STYLE = 'color:#2563eb;text-decoration:underline';
    /** Heading sizes, by '#' count — a description never owns the page's H1. */
    private const HEADING_SIZE = [1 => '16px', 2 => '15px', 3 => '14px'];

    public static function toHtml(?string $text): string
    {
        if ($text === null || trim($text) === '') {
            return '';
        }

        $html = '';
        foreach (self::blocks($text) as $block) {
            $html .= match ($block['kind']) {
                'h'  => sprintf(
                    '<div style="font-weight:700;font-size:%s;margin:10px 0 4px">%s</div>',
                    self::HEADING_SIZE[$block['level']] ?? '14px',
                    self::inline($block['text']),
                ),
                'ul', 'ol' => sprintf(
                    '<%1$s style="margin:6px 0;padding-left:18px">%2$s</%1$s>',
                    $block['kind'],
                    implode('', array_map(
                        fn ($item) => '<li>' . self::inline($item) . '</li>',
                        $block['items'],
                    )),
                ),
                default => sprintf(
                    '<p style="margin:0 0 8px">%s</p>',
                    implode('<br>', array_map(fn ($line) => self::inline($line), $block['lines'])),
                ),
            };
        }

        return $html;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function blocks(string $text): array
    {
        $blocks = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            if (preg_match(self::HEADING, $line, $m)) {
                $blocks[] = ['kind' => 'h', 'level' => strlen($m[1]), 'text' => $m[2]];
                continue;
            }

            $listKind = null;
            if (preg_match(self::BULLET, $line, $m))      $listKind = 'ul';
            elseif (preg_match(self::NUMBER, $line, $m))  $listKind = 'ol';

            $lastIndex = count($blocks) - 1;
            $last = $lastIndex >= 0 ? $blocks[$lastIndex] : null;

            if ($listKind !== null) {
                if ($last && $last['kind'] === $listKind) $blocks[$lastIndex]['items'][] = $m[1];
                else $blocks[] = ['kind' => $listKind, 'items' => [$m[1]]];
                continue;
            }

            // A blank line closes the current paragraph/list; consecutive text
            // lines stay one paragraph, joined with <br>.
            if (trim($line) === '') {
                $blocks[] = ['kind' => 'p', 'lines' => []];
                continue;
            }
            if ($last && $last['kind'] === 'p' && $last['lines'] !== []) $blocks[$lastIndex]['lines'][] = $line;
            else $blocks[] = ['kind' => 'p', 'lines' => [$line]];
        }

        return array_values(array_filter(
            $blocks,
            fn ($b) => $b['kind'] === 'p' ? $b['lines'] !== [] : ($b['kind'] === 'h' ? trim($b['text']) !== '' : $b['items'] !== []),
        ));
    }

    /** Named links resolve first, so a label can never be re-parsed into a nested anchor. */
    private static function inline(string $text): string
    {
        $out    = '';
        $cursor = 0;

        preg_match_all(self::NAMED_LINK, $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $i => [$whole, $at]) {
            $out .= self::autoLinks(substr($text, $cursor, $at - $cursor));
            $label = $matches[1][$i][0];
            $href  = self::safeHref($matches[2][$i][0]);
            // Unsupported scheme (javascript:, data:, …) — keep the label as
            // plain text rather than dropping what the author wrote.
            $out .= $href === null ? self::emphasis($label) : self::anchor($href, self::emphasis($label));
            $cursor = $at + strlen($whole);
        }

        return $out . self::autoLinks(substr($text, $cursor));
    }

    private static function autoLinks(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $out    = '';
        $cursor = 0;

        preg_match_all(self::AUTO_LINK, $text, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$raw, $at]) {
            $out .= self::emphasis(substr($text, $cursor, $at - $cursor));
            [$url, $trailing] = self::splitTrailingPunctuation($raw);
            $href = self::safeHref($url);
            $out .= $href === null ? e($url) : self::anchor($href, e($url));
            $out .= e($trailing);
            $cursor = $at + strlen($raw);
        }

        return $out . self::emphasis(substr($text, $cursor));
    }

    /** **bold** / _italic_, innermost-first so "**bold _and_ italic**" nests. */
    private static function emphasis(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (preg_match(self::BOLD, $text, $m, PREG_OFFSET_CAPTURE)) {
            return self::emphasis(substr($text, 0, $m[0][1]))
                . '<strong>' . self::emphasis($m[1][0]) . '</strong>'
                . self::emphasis(substr($text, $m[0][1] + strlen($m[0][0])));
        }
        if (preg_match(self::ITALIC, $text, $m, PREG_OFFSET_CAPTURE)) {
            $inner = $m[1][0] !== '' ? $m[1][0] : ($m[2][0] ?? '');
            return self::emphasis(substr($text, 0, $m[0][1]))
                . '<em>' . e($inner) . '</em>'
                . self::emphasis(substr($text, $m[0][1] + strlen($m[0][0])));
        }

        return e($text);
    }

    private static function anchor(string $href, string $innerHtml): string
    {
        return sprintf(
            '<a href="%s" target="_blank" rel="noopener noreferrer" style="%s">%s</a>',
            e($href),
            self::LINK_STYLE,
            $innerHtml,
        );
    }

    /**
     * A url that ends a sentence ("see https://x.com/a.") must not carry the
     * full stop into the href. A closing bracket is only dropped when it's
     * unbalanced, so …/wiki/Foo_(bar) survives.
     *
     * @return array{0: string, 1: string} [url, trailing punctuation]
     */
    private static function splitTrailingPunctuation(string $url): array
    {
        $end = strlen($url);
        while ($end > 0) {
            $char = $url[$end - 1];
            if (str_contains('.,;:!?"\'', $char)) { $end--; continue; }
            if ($char === ')') {
                $slice = substr($url, 0, $end);
                if (substr_count($slice, ')') > substr_count($slice, '(')) { $end--; continue; }
            }
            break;
        }

        return [substr($url, 0, $end), substr($url, $end)];
    }

    /** Only ever returns an http/https/mailto url; anything else is null. */
    private static function safeHref(string $raw): ?string
    {
        $candidate = str_starts_with($raw, 'www.')
            ? "https://{$raw}"
            : ((!str_contains($raw, '://') && str_contains($raw, '@')) ? "mailto:{$raw}" : $raw);

        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));

        if ($scheme === 'mailto') {
            $address = substr($candidate, strlen('mailto:'));
            return filter_var($address, FILTER_VALIDATE_EMAIL) ? "mailto:{$address}" : null;
        }
        if (in_array($scheme, ['http', 'https'], true)) {
            return filter_var($candidate, FILTER_VALIDATE_URL) ? $candidate : null;
        }

        return null;
    }
}
