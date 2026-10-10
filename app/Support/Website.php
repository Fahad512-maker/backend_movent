<?php

namespace App\Support;

/**
 * Normalises a website a user typed into something that works as an href.
 *
 * People type "acme.com", not "https://acme.com" — and a bare host in an
 * <a href> resolves as a RELATIVE path, so the link silently points at
 * /acme.com on whatever origin is rendering it. That matters more than usual
 * here: a brand's website appears in the "Billed From" block on the emailed
 * invoice, the public share link, the client portal and the preview, so a
 * broken href would be broken in four places at once.
 *
 * Normalising once on the way IN (Brand store/update) rather than at each of
 * those render points is deliberate: the stored value is then always a usable
 * absolute URL, every surface can use it as both href and label, and there is
 * no per-surface helper to drift out of sync.
 */
class Website
{
    public static function normalise(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        // Already carries a scheme — left exactly as typed, including a
        // deliberate http:// for a host that has no certificate.
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $value)) {
            return $value;
        }

        // Protocol-relative ("//acme.com") is still ambiguous in an email
        // client, which has no page origin to inherit from.
        return 'https://' . ltrim($value, '/');
    }
}
