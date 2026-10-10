<?php

namespace Tests\Unit;

use App\Services\UserCompanyRoleService;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for App\Services\UserCompanyRoleService::resolveFromPayload() — the
 * one place that decides WHICH roles a request is asking for.
 *
 * Three callers with three payload shapes share it (the multi-role wizard's
 * per-company `roles`, a top-level `roles`, and every older caller's single
 * `role_type`), and both user controllers route every create/invite/update
 * through it. If the precedence here slipped, a company's roles would quietly
 * come from the wrong field — so the legacy path is pinned as hard as the new
 * one.
 *
 * Pure PHPUnit: the method takes arrays and returns an array, no Laravel.
 */
class UserCompanyRolePayloadTest extends TestCase
{
    // ── Precedence ───────────────────────────────────────────────────────────

    /** Per-company roles are the most specific answer and must win outright. */
    public function test_per_company_roles_beat_top_level_and_legacy(): void
    {
        $this->assertSame(
            ['qa'],
            UserCompanyRoleService::resolveFromPayload(
                ['roles' => ['qa']],
                ['seller', 'project_manager'],
                'hr'
            )
        );
    }

    /** With no per-company entry, the shared top-level list applies. */
    public function test_top_level_roles_beat_legacy_role_type(): void
    {
        $this->assertSame(
            ['seller', 'project_manager'],
            UserCompanyRoleService::resolveFromPayload([], ['seller', 'project_manager'], 'hr')
        );
    }

    /**
     * The legacy field still works on its own. This is what keeps every caller
     * that never heard of `roles` — invites, older integrations, the Team
     * Resources quick-add form — creating users with a role at all.
     */
    public function test_legacy_role_type_is_used_when_no_roles_are_sent(): void
    {
        $this->assertSame(['hr'], UserCompanyRoleService::resolveFromPayload([], null, 'hr'));
        $this->assertSame(['hr'], UserCompanyRoleService::resolveFromPayload([], [], 'hr'));
    }

    /** Nothing sent means nothing to write — which callers read as "leave existing roles alone". */
    public function test_nothing_sent_resolves_to_an_empty_list(): void
    {
        $this->assertSame([], UserCompanyRoleService::resolveFromPayload([], null, null));
        $this->assertSame([], UserCompanyRoleService::resolveFromPayload(['roles' => []], [], null));
    }

    /**
     * An EMPTY per-company list must fall through rather than mean "no roles
     * here". The wizard sends `roles: []` for a company it has not configured
     * yet, and treating that as an explicit "none" would leave that company
     * role-less while the top-level selection said otherwise.
     */
    public function test_empty_per_company_roles_fall_through_to_the_shared_list(): void
    {
        $this->assertSame(
            ['seller'],
            UserCompanyRoleService::resolveFromPayload(['roles' => []], ['seller'], null)
        );
    }

    // ── Normalisation ────────────────────────────────────────────────────────

    /**
     * Order is preserved because entry 0 becomes the PRIMARY role — the one
     * users.role_type mirrors and the roster shows. Sorting here would silently
     * reassign someone's headline role.
     */
    public function test_order_is_preserved_so_the_first_entry_stays_primary(): void
    {
        $this->assertSame(
            ['project_manager', 'seller', 'qa'],
            UserCompanyRoleService::resolveFromPayload(
                ['roles' => ['project_manager', 'seller', 'qa']],
                null,
                null
            )
        );
    }

    /** Duplicates collapse, and the FIRST occurrence keeps its position. */
    public function test_duplicates_are_removed_keeping_the_earliest_position(): void
    {
        $this->assertSame(
            ['seller', 'qa'],
            UserCompanyRoleService::resolveFromPayload(
                ['roles' => ['seller', 'qa', 'seller']],
                null,
                null
            )
        );
    }

    /** Whitespace and blanks are request noise, not roles. */
    public function test_blank_and_whitespace_entries_are_dropped(): void
    {
        $this->assertSame(
            ['seller', 'hr'],
            UserCompanyRoleService::resolveFromPayload(
                ['roles' => ['  seller  ', '', '   ', 'hr']],
                null,
                null
            )
        );
    }

    /**
     * A payload is untrusted input: non-string entries (a stray array or null
     * from a hand-rolled API call) must be discarded, never coerced into a
     * role name.
     */
    public function test_non_string_entries_are_discarded(): void
    {
        $this->assertSame(
            ['seller'],
            UserCompanyRoleService::resolveFromPayload(
                ['roles' => ['seller', null, 123, ['nested']]],
                null,
                null
            )
        );
    }

    /** The returned list is always a clean 0-indexed array, never a sparse one. */
    public function test_result_is_a_list_not_a_sparse_array(): void
    {
        $roles = UserCompanyRoleService::resolveFromPayload(
            ['roles' => ['', 'seller', '', 'qa']],
            null,
            null
        );

        $this->assertSame([0, 1], array_keys($roles));
    }
}
