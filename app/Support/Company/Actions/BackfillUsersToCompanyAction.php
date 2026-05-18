<?php

declare(strict_types=1);

namespace App\Support\Company\Actions;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * For every user in the given company's tenant whose default/current
 * company is unset, fill it with the given company.
 *
 * Idempotent — users who already have an explicit choice are skipped.
 * Wrapped in a DB transaction; either the entire backfill succeeds or
 * nothing changes.
 *
 * Two real callers:
 *
 *   1) DemoUsersSeeder (H1a) — after creating each tenant's first
 *      Company, calls this to bind every seeded user to it. Without the
 *      backfill, those users would hit ResolveCompany's Step 4 sole-
 *      fallback on every request, which works but persists noise on
 *      every login. Running the action upfront sets explicit defaults.
 *
 *   2) Future company-creation endpoint (admin settings, separate slice)
 *      — when a tenant provisions a second/third company, this action
 *      runs in the same transaction as the company INSERT. Existing
 *      users keep their original company as default/current; only users
 *      with null defaults are affected. Without this, those users would
 *      hit Step 5 → company_required when company #2 lands, since the
 *      sole-fallback stops firing. CLAUDE.md §3 documents this as the
 *      locked transition behavior (Approach A).
 *
 * Returns the number of users whose row was updated.
 */
final class BackfillUsersToCompanyAction
{
    public function execute(Company $company): int
    {
        return DB::transaction(function () use ($company): int {
            $tenantId = $company->tenant_id;
            $companyId = $company->id;

            // Single UPDATE for users with null default. Use COALESCE so we
            // touch only the columns that are currently null, preserving
            // explicit user choices.
            $updated = User::query()
                ->where('tenant_id', $tenantId)
                ->where(function ($q) {
                    $q->whereNull('default_company_id')
                        ->orWhereNull('current_company_id');
                })
                ->get();

            $count = 0;
            foreach ($updated as $user) {
                $attrs = [];
                if ($user->default_company_id === null) {
                    $attrs['default_company_id'] = $companyId;
                }
                if ($user->current_company_id === null) {
                    $attrs['current_company_id'] = $companyId;
                }
                if ($attrs !== []) {
                    $user->forceFill($attrs)->save();
                    $count++;
                }
            }

            return $count;
        });
    }
}
