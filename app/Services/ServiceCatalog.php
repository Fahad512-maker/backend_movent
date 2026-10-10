<?php

namespace App\Services;

/**
 * The built-in catalogue of IT services a company can offer its clients.
 *
 * Why a code catalogue rather than 37 rows seeded per company:
 *
 *   • Stable slugs. The recommendation rules below are keyed on them, so
 *     "a client who had a website built should be offered SEO" keeps working
 *     no matter what a Company Admin renames their own copy of the service to.
 *     Matching on admin-entered names would break the first time someone
 *     wrote "Web Development" instead of "Website Development".
 *   • Nothing to backfill. Adding a service here makes it available to every
 *     company at once; seeding would leave existing tenants behind.
 *   • A company_services row is only written when an admin actually touches a
 *     service (enables it, edits it, prices it), so the table holds decisions
 *     rather than 37 untouched defaults per tenant.
 *
 * A Company Admin can still add services of their own — those rows carry a
 * null slug and simply take no part in the recommendation rules.
 *
 * `keywords` back the fallback detection used on projects that were never
 * tagged with a service (see ServiceRecommendationService). They are matched
 * against project and invoice names, so they are deliberately the words that
 * actually appear in real project titles.
 */
class ServiceCatalog
{
    /**
     * slug => [name, description, icon, group, keywords[], recommends[]]
     *
     * `recommends` is "a client who already has THIS would sensibly want
     * THOSE" — the direction the client portal reads it in.
     */
    public const SERVICES = [
        // ── Web ──────────────────────────────────────────────────────────────
        'website_design' => [
            'name' => 'Website Design',
            'description' => 'A modern, responsive design for your website — built around how your customers actually browse.',
            'icon' => '🎨', 'group' => 'Web',
            'keywords' => ['website design', 'web design', 'landing page', 'redesign'],
            'recommends' => ['website_development', 'ui_ux_design', 'logo_brand_identity'],
        ],
        'website_development' => [
            'name' => 'Website Development',
            'description' => 'Your design turned into a fast, secure, search-friendly website you can update yourself.',
            'icon' => '💻', 'group' => 'Web',
            'keywords' => ['website development', 'web development', 'website', 'wordpress', 'webflow', 'cms'],
            'recommends' => ['seo', 'website_maintenance', 'website_speed_optimization', 'domain_hosting', 'cyber_security', 'backup_recovery'],
        ],
        'ecommerce_development' => [
            'name' => 'E-Commerce Development',
            'description' => 'An online store with payments, stock and shipping wired up end to end.',
            'icon' => '🛒', 'group' => 'Web',
            'keywords' => ['ecommerce', 'e-commerce', 'online store', 'shop', 'woocommerce', 'shopify'],
            'recommends' => ['seo', 'google_ads', 'meta_ads', 'website_maintenance', 'cyber_security', 'backup_recovery'],
        ],
        'custom_web_applications' => [
            'name' => 'Custom Web Applications',
            'description' => 'Software built for the way your business actually works, instead of bending it to fit a tool.',
            'icon' => '⚙️', 'group' => 'Web',
            'keywords' => ['web application', 'web app', 'portal', 'dashboard', 'custom application'],
            'recommends' => ['api_integration', 'qa_testing', 'website_maintenance', 'cloud_solutions'],
        ],
        'saas_development' => [
            'name' => 'SaaS Development',
            'description' => 'A multi-tenant product with subscriptions, billing and onboarding built in.',
            'icon' => '☁️', 'group' => 'Web',
            'keywords' => ['saas', 'subscription platform', 'multi-tenant'],
            'recommends' => ['cloud_solutions', 'qa_testing', 'api_integration'],
        ],

        // ── Mobile ───────────────────────────────────────────────────────────
        'mobile_app_development' => [
            'name' => 'Mobile App Development (Android & iOS)',
            'description' => 'One app, both stores — native-feeling on Android and iOS, submitted and released for you.',
            'icon' => '📱', 'group' => 'Mobile',
            'keywords' => ['mobile app', 'android', 'ios', 'app development', 'flutter', 'react native'],
            'recommends' => ['bug_fixing', 'technical_support', 'qa_testing', 'ui_ux_design'],
        ],

        // ── Design & brand ───────────────────────────────────────────────────
        'ui_ux_design' => [
            'name' => 'UI/UX Design',
            'description' => 'Screens and flows tested against real tasks, so people finish what they came to do.',
            'icon' => '🧭', 'group' => 'Design',
            'keywords' => ['ui', 'ux', 'user experience', 'wireframe', 'prototype'],
            'recommends' => ['graphic_design', 'website_development', 'mobile_app_development'],
        ],
        'graphic_design' => [
            'name' => 'Graphic Design',
            'description' => 'Print and digital artwork that stays recognisably yours across every placement.',
            'icon' => '🖌️', 'group' => 'Design',
            'keywords' => ['graphic design', 'brochure', 'flyer', 'banner', 'artwork', 'creative'],
            'recommends' => ['logo_brand_identity', 'social_media_marketing', 'animation_motion_graphics'],
        ],
        'logo_brand_identity' => [
            'name' => 'Logo & Brand Identity',
            'description' => 'A logo, palette and type system with the guidelines to keep them consistent.',
            'icon' => '✨', 'group' => 'Design',
            'keywords' => ['logo', 'brand identity', 'branding', 'brand guidelines', 'rebrand'],
            'recommends' => ['graphic_design', 'social_media_marketing', 'website_design'],
        ],
        'video_editing' => [
            'name' => 'Video Editing',
            'description' => 'Your footage cut, graded and captioned for the platform it is going out on.',
            'icon' => '🎬', 'group' => 'Design',
            'keywords' => ['video editing', 'video', 'reel', 'youtube'],
            'recommends' => ['animation_motion_graphics', 'social_media_marketing'],
        ],
        'animation_motion_graphics' => [
            'name' => 'Animation & Motion Graphics',
            'description' => 'Explainers and animated graphics that make a complicated offer land in seconds.',
            'icon' => '🌀', 'group' => 'Design',
            'keywords' => ['animation', 'motion graphics', 'explainer', 'after effects'],
            'recommends' => ['video_editing', 'graphic_design'],
        ],

        // ── Marketing ────────────────────────────────────────────────────────
        'seo' => [
            'name' => 'SEO (Search Engine Optimization)',
            'description' => 'Technical fixes, content and links aimed at the searches that actually convert.',
            'icon' => '🔍', 'group' => 'Marketing',
            'keywords' => ['seo', 'search engine optimization', 'organic'],
            'recommends' => ['local_seo', 'content_writing', 'google_ads', 'website_speed_optimization'],
        ],
        'local_seo' => [
            'name' => 'Local SEO',
            'description' => 'Show up in map results and local searches where your customers are.',
            'icon' => '📍', 'group' => 'Marketing',
            'keywords' => ['local seo', 'google business', 'maps listing'],
            'recommends' => ['seo', 'google_ads', 'social_media_marketing'],
        ],
        'social_media_marketing' => [
            'name' => 'Social Media Marketing',
            'description' => 'A planned content calendar, produced and posted, with the numbers reported back.',
            'icon' => '📣', 'group' => 'Marketing',
            'keywords' => ['social media', 'smm', 'content calendar', 'instagram', 'linkedin'],
            'recommends' => ['meta_ads', 'graphic_design', 'content_writing', 'video_editing'],
        ],
        'google_ads' => [
            'name' => 'Google Ads (PPC)',
            'description' => 'Search and shopping campaigns managed to a cost per lead, not to a spend target.',
            'icon' => '🎯', 'group' => 'Marketing',
            'keywords' => ['google ads', 'adwords', 'ppc', 'paid search'],
            'recommends' => ['seo', 'copywriting', 'meta_ads'],
        ],
        'meta_ads' => [
            'name' => 'Facebook & Instagram Ads',
            'description' => 'Paid social campaigns with the creative and audiences tested against each other.',
            'icon' => '📢', 'group' => 'Marketing',
            'keywords' => ['facebook ads', 'instagram ads', 'meta ads', 'paid social'],
            'recommends' => ['social_media_marketing', 'graphic_design', 'video_editing'],
        ],
        'content_writing' => [
            'name' => 'Content Writing',
            'description' => 'Articles and page copy written for both the reader and the search engine.',
            'icon' => '✍️', 'group' => 'Marketing',
            'keywords' => ['content writing', 'blog', 'articles', 'content'],
            'recommends' => ['seo', 'copywriting'],
        ],
        'copywriting' => [
            'name' => 'Copywriting',
            'description' => 'Headlines, landing pages and ad copy written to be acted on.',
            'icon' => '🖊️', 'group' => 'Marketing',
            'keywords' => ['copywriting', 'copy', 'sales page'],
            'recommends' => ['content_writing', 'google_ads'],
        ],

        // ── Maintain & fix ───────────────────────────────────────────────────
        'bug_fixing' => [
            'name' => 'Bug Fixing',
            'description' => 'Something broken traced to its cause and fixed properly, not patched over.',
            'icon' => '🐛', 'group' => 'Support',
            'keywords' => ['bug fix', 'bug fixing', 'fixes', 'defect'],
            'recommends' => ['qa_testing', 'website_maintenance', 'technical_support'],
        ],
        'website_maintenance' => [
            'name' => 'Website Maintenance',
            'description' => 'Updates, monitoring and content changes handled monthly so nothing drifts.',
            'icon' => '🔧', 'group' => 'Support',
            'keywords' => ['maintenance', 'retainer', 'support contract', 'amc'],
            'recommends' => ['website_speed_optimization', 'security_malware_removal', 'backup_recovery'],
        ],
        'website_speed_optimization' => [
            'name' => 'Website Speed Optimization',
            'description' => 'Core Web Vitals brought into the green — faster pages, better rankings.',
            'icon' => '⚡', 'group' => 'Support',
            'keywords' => ['speed optimization', 'performance', 'page speed', 'core web vitals'],
            'recommends' => ['website_maintenance', 'domain_hosting', 'seo'],
        ],
        'security_malware_removal' => [
            'name' => 'Security & Malware Removal',
            'description' => 'Infections cleaned out, the way in closed, and monitoring left in place.',
            'icon' => '🛡️', 'group' => 'Support',
            'keywords' => ['malware', 'hacked', 'security cleanup', 'virus'],
            'recommends' => ['cyber_security', 'website_maintenance', 'backup_recovery'],
        ],
        'technical_support' => [
            'name' => 'Technical Support',
            'description' => 'A team to call when something stops working, with an answer time you can plan around.',
            'icon' => '🎧', 'group' => 'Support',
            'keywords' => ['technical support', 'helpdesk', 'support'],
            'recommends' => ['website_maintenance', 'bug_fixing'],
        ],
        'qa_testing' => [
            'name' => 'QA & Testing',
            'description' => 'Functional, regression and device testing before your users find the problems.',
            'icon' => '🧪', 'group' => 'Support',
            'keywords' => ['qa', 'testing', 'quality assurance', 'test automation'],
            'recommends' => ['bug_fixing', 'cyber_security'],
        ],

        // ── Infrastructure ───────────────────────────────────────────────────
        'cloud_solutions' => [
            'name' => 'Cloud Solutions',
            'description' => 'Hosting that scales with demand, set up and handed over documented.',
            'icon' => '⛅', 'group' => 'Infrastructure',
            'keywords' => ['cloud', 'aws', 'azure', 'devops', 'kubernetes'],
            'recommends' => ['domain_hosting', 'backup_recovery', 'data_migration'],
        ],
        'domain_hosting' => [
            'name' => 'Domain & Hosting',
            'description' => 'Domains, certificates and hosting managed together, renewals included.',
            'icon' => '🌐', 'group' => 'Infrastructure',
            'keywords' => ['hosting', 'domain', 'ssl', 'dns'],
            'recommends' => ['email_setup', 'cyber_security', 'backup_recovery'],
        ],
        'email_setup' => [
            'name' => 'Email Setup',
            'description' => 'Business email configured with the records that keep it out of spam folders.',
            'icon' => '📧', 'group' => 'Infrastructure',
            'keywords' => ['email setup', 'google workspace', 'microsoft 365', 'smtp'],
            'recommends' => ['domain_hosting', 'technical_support'],
        ],
        'cyber_security' => [
            'name' => 'Cyber Security',
            'description' => 'Audits, hardening and monitoring sized to what you actually have to protect.',
            'icon' => '🔒', 'group' => 'Infrastructure',
            'keywords' => ['cyber security', 'penetration test', 'security audit', 'vapt'],
            'recommends' => ['security_malware_removal', 'backup_recovery', 'website_maintenance'],
        ],
        'backup_recovery' => [
            'name' => 'Backup & Recovery',
            'description' => 'Automated backups that are actually restore-tested, with a recovery plan on file.',
            'icon' => '💾', 'group' => 'Infrastructure',
            'keywords' => ['backup', 'disaster recovery', 'restore'],
            'recommends' => ['cyber_security', 'cloud_solutions'],
        ],
        'data_migration' => [
            'name' => 'Data Migration',
            'description' => 'Moving off an old system without losing history or downtime you can\'t afford.',
            'icon' => '📦', 'group' => 'Infrastructure',
            'keywords' => ['data migration', 'migration', 'import data'],
            'recommends' => ['backup_recovery', 'cloud_solutions'],
        ],

        // ── Business systems ─────────────────────────────────────────────────
        'crm_development' => [
            'name' => 'CRM Development',
            'description' => 'A pipeline your sales team will actually keep up to date, fitted to how they sell.',
            'icon' => '🤝', 'group' => 'Business Systems',
            'keywords' => ['crm'],
            'recommends' => ['api_integration', 'erp_development', 'ai_automation'],
        ],
        'erp_development' => [
            'name' => 'ERP Development',
            'description' => 'Stock, purchasing, accounts and payroll working off one set of numbers.',
            'icon' => '🏭', 'group' => 'Business Systems',
            'keywords' => ['erp'],
            'recommends' => ['crm_development', 'api_integration', 'data_migration'],
        ],
        'api_integration' => [
            'name' => 'API Integration',
            'description' => 'Your tools talking to each other, so nobody re-types the same data twice.',
            'icon' => '🔗', 'group' => 'Business Systems',
            'keywords' => ['api integration', 'api', 'integration', 'webhook'],
            'recommends' => ['custom_web_applications', 'qa_testing'],
        ],
        'ai_automation' => [
            'name' => 'AI Automation',
            'description' => 'The repetitive work in your process handed to automation, with a human check where it matters.',
            'icon' => '🤖', 'group' => 'Business Systems',
            'keywords' => ['ai', 'automation', 'chatbot', 'machine learning'],
            'recommends' => ['api_integration', 'crm_development', 'saas_development'],
        ],

        // ── Advisory & people ────────────────────────────────────────────────
        'it_consultancy' => [
            'name' => 'IT Consultancy',
            'description' => 'An outside read on your stack and roadmap before you commit the budget.',
            'icon' => '💡', 'group' => 'Advisory',
            'keywords' => ['consultancy', 'consulting', 'advisory', 'audit'],
            'recommends' => ['cloud_solutions', 'cyber_security', 'ai_automation'],
        ],
        'dedicated_resource_hiring' => [
            'name' => 'Dedicated Resource Hiring',
            'description' => 'A developer, designer or QA engineer working only on your work, monthly.',
            'icon' => '👤', 'group' => 'Advisory',
            'keywords' => ['dedicated resource', 'staff augmentation', 'dedicated developer'],
            'recommends' => ['it_consultancy', 'qa_testing'],
        ],
        'other_it_services' => [
            'name' => 'Other IT Services',
            'description' => 'Something else in mind? Tell us what you need and we will scope it.',
            'icon' => '➕', 'group' => 'Advisory',
            'keywords' => [],
            'recommends' => [],
        ],
    ];

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return self::SERVICES;
    }

    public static function has(string $slug): bool
    {
        return array_key_exists($slug, self::SERVICES);
    }

    /** @return array<string, mixed>|null */
    public static function get(string $slug): ?array
    {
        return self::SERVICES[$slug] ?? null;
    }

    public static function slugs(): array
    {
        return array_keys(self::SERVICES);
    }

    /**
     * What to offer a client who already has the given services.
     *
     * Ordered by how many of the client's services point at each suggestion,
     * so a client with both a website and a shop sees Maintenance (recommended
     * by both) above something only one of them implies. Anything the client
     * already has is dropped — nobody wants to be sold what they just bought.
     *
     * @param  string[]  $ownedSlugs
     * @return string[]  suggestion slugs, strongest first
     */
    public static function recommendationsFor(array $ownedSlugs): array
    {
        $owned = array_flip($ownedSlugs);
        $score = [];

        foreach ($ownedSlugs as $slug) {
            foreach (self::SERVICES[$slug]['recommends'] ?? [] as $suggestion) {
                if (isset($owned[$suggestion])) {
                    continue;
                }
                $score[$suggestion] = ($score[$suggestion] ?? 0) + 1;
            }
        }

        // arsort keeps insertion order within equal scores, so the catalogue's
        // own ordering breaks ties predictably instead of arbitrarily.
        arsort($score);

        return array_keys($score);
    }

    /**
     * Best-effort read of which services a piece of free text refers to —
     * used on projects and invoices that were never tagged with a service.
     *
     * Longest keyword first so "website development" wins over the bare
     * "website" it contains; without that a development project would be read
     * as a design one purely by keyword length.
     *
     * @return string[] slugs
     */
    public static function detectSlugs(string $text): array
    {
        $text = ' ' . strtolower(preg_replace('/\s+/', ' ', $text)) . ' ';

        $pairs = [];
        foreach (self::SERVICES as $slug => $service) {
            foreach ($service['keywords'] as $keyword) {
                $pairs[] = [$keyword, $slug];
            }
        }

        usort($pairs, fn ($a, $b) => strlen($b[0]) <=> strlen($a[0]));

        $found = [];
        foreach ($pairs as [$keyword, $slug]) {
            if (isset($found[$slug])) {
                continue;
            }
            if (str_contains($text, ' ' . $keyword) || str_contains($text, $keyword . ' ')) {
                $found[$slug] = true;
            }
        }

        return array_keys($found);
    }
}
