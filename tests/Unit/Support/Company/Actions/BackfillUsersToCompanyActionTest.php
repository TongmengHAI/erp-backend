<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Company\Actions\BackfillUsersToCompanyAction;

it('fills null default_company_id and current_company_id on users in the same tenant', function (): void {
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $u1 = User::factory()->forTenant($tenant)->create([
        'default_company_id' => null,
        'current_company_id' => null,
    ]);
    $u2 = User::factory()->forTenant($tenant)->create([
        'default_company_id' => null,
        'current_company_id' => null,
    ]);

    $count = app(BackfillUsersToCompanyAction::class)->execute($company);

    expect($count)->toBe(2);
    expect($u1->fresh()->default_company_id)->toBe($company->id);
    expect($u1->fresh()->current_company_id)->toBe($company->id);
    expect($u2->fresh()->default_company_id)->toBe($company->id);
    expect($u2->fresh()->current_company_id)->toBe($company->id);
});

it('skips users who already have explicit choices (idempotent re-run)', function (): void {
    $tenant = Tenant::factory()->create();
    $companyA = Company::factory()->forTenant($tenant)->create();
    $companyB = Company::factory()->forTenant($tenant)->create();

    // u1 has explicit choices for company A.
    $u1 = User::factory()->forTenant($tenant)->create([
        'default_company_id' => $companyA->id,
        'current_company_id' => $companyA->id,
    ]);
    // u2 has no choice yet.
    $u2 = User::factory()->forTenant($tenant)->create([
        'default_company_id' => null,
        'current_company_id' => null,
    ]);

    // Running backfill against company B should:
    //   - Leave u1 alone (already has A).
    //   - Set u2's defaults to B.
    $count = app(BackfillUsersToCompanyAction::class)->execute($companyB);

    expect($count)->toBe(1);
    expect($u1->fresh()->default_company_id)->toBe($companyA->id);
    expect($u1->fresh()->current_company_id)->toBe($companyA->id);
    expect($u2->fresh()->default_company_id)->toBe($companyB->id);
    expect($u2->fresh()->current_company_id)->toBe($companyB->id);

    // Re-running the action is a no-op (idempotent).
    $second = app(BackfillUsersToCompanyAction::class)->execute($companyB);
    expect($second)->toBe(0);
});

it('does not affect users in other tenants', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $companyA = Company::factory()->forTenant($tenantA)->create();

    $userA = User::factory()->forTenant($tenantA)->create([
        'default_company_id' => null,
        'current_company_id' => null,
    ]);
    $userB = User::factory()->forTenant($tenantB)->create([
        'default_company_id' => null,
        'current_company_id' => null,
    ]);

    app(BackfillUsersToCompanyAction::class)->execute($companyA);

    expect($userA->fresh()->default_company_id)->toBe($companyA->id);
    // Cross-tenant: user in tenant B is untouched.
    expect($userB->fresh()->default_company_id)->toBeNull();
    expect($userB->fresh()->current_company_id)->toBeNull();
});

it('handles a partial state: default set but current null, or vice versa', function (): void {
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    $other = Company::factory()->forTenant($tenant)->create();

    // u1: explicit default, null current — only current gets filled.
    $u1 = User::factory()->forTenant($tenant)->create([
        'default_company_id' => $other->id,
        'current_company_id' => null,
    ]);
    // u2: null default, explicit current — only default gets filled.
    $u2 = User::factory()->forTenant($tenant)->create([
        'default_company_id' => null,
        'current_company_id' => $other->id,
    ]);

    $count = app(BackfillUsersToCompanyAction::class)->execute($company);

    expect($count)->toBe(2);
    expect($u1->fresh()->default_company_id)->toBe($other->id); // preserved
    expect($u1->fresh()->current_company_id)->toBe($company->id);
    expect($u2->fresh()->default_company_id)->toBe($company->id);
    expect($u2->fresh()->current_company_id)->toBe($other->id); // preserved
});
