<?php

declare(strict_types=1);

use App\Domain\HRM\Models\HrmSettings;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\Framework\DefaultPermissionsSeeder;
use Database\Seeders\Framework\DefaultRolesSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader('Origin', 'http://localhost');
    $this->seed([DefaultPermissionsSeeder::class, DefaultRolesSeeder::class]);

    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    $this->admin = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $this->admin->assignTenantRole($this->tenant, 'tenant_admin');

    // Session 2 entitlement: admin/hrm/* requires an Active HRM
    // tenant_modules row. The TenantFactory's afterCreating() hook
    // grants it automatically, mirroring the production backfill.

    $this->settings = HrmSettings::query()
        ->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->first();
});

it('updates auto-gen + prefix together and returns the refreshed resource', function (): void {
    $this->actingAs($this->admin);
    $response = $this->patchJson("/api/v1/admin/hrm/settings/{$this->settings->id}", [
        'auto_generate_employee_code' => true,
        'employee_code_prefix' => 'TT-',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.auto_generate_employee_code', true);
    $response->assertJsonPath('data.employee_code_prefix', 'TT-');
});

it('returns 401 when called with no authenticated session', function (): void {
    $this->patchJson("/api/v1/admin/hrm/settings/{$this->settings->id}", [
        'auto_generate_employee_code' => true,
        'employee_code_prefix' => 'TT-',
    ])->assertStatus(401);
});

it('returns 403 when the user lacks settings.hrm.update permission', function (): void {
    $viewer = User::factory()->forTenant($this->tenant)->create([
        'default_company_id' => $this->company->id,
        'current_company_id' => $this->company->id,
    ]);
    $viewer->assignTenantRole($this->tenant, 'viewer');  // .view only, no .update

    $this->actingAs($viewer)
        ->patchJson("/api/v1/admin/hrm/settings/{$this->settings->id}", [
            'auto_generate_employee_code' => true,
            'employee_code_prefix' => 'TT-',
        ])
        ->assertStatus(403);
});

it('LOAD-BEARING: 422 when toggling auto-gen ON without a prefix (cross-field rule)', function (): void {
    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/admin/hrm/settings/{$this->settings->id}", [
        'auto_generate_employee_code' => true,
        // prefix omitted; the existing row's prefix is NULL → cross-field
        // check fires → 422 with errors.employee_code_prefix
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('employee_code_prefix');
});

it('LOAD-BEARING: 422 when setting prefix to NULL while auto-gen is currently ON (same rule from the other angle)', function (): void {
    // First enable auto-gen with a valid prefix.
    $this->settings->update([
        'auto_generate_employee_code' => true,
        'employee_code_prefix' => 'TT-',
    ]);

    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/admin/hrm/settings/{$this->settings->id}", [
        'employee_code_prefix' => null,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('employee_code_prefix');
});

it('returns 422 when prefix violates the alphabet rule (lowercase)', function (): void {
    $this->actingAs($this->admin);
    $this->patchJson("/api/v1/admin/hrm/settings/{$this->settings->id}", [
        'auto_generate_employee_code' => true,
        'employee_code_prefix' => 'tt-',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('employee_code_prefix');
});

it('returns 422 when default_employee_status is not in the enum', function (): void {
    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/hrm/settings/{$this->settings->id}", [
            'default_employee_status' => 'gibberish',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('default_employee_status');
});

it('returns 404 cross-tenant — admin cannot update another tenant\'s settings via id', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherCompany = Company::factory()->forTenant($otherTenant)->create();
    $otherSettings = HrmSettings::query()
        ->withoutGlobalScopes()
        ->where('company_id', $otherCompany->id)
        ->first();

    $this->actingAs($this->admin)
        ->patchJson("/api/v1/admin/hrm/settings/{$otherSettings->id}", [
            'employee_code_prefix' => 'HIJACK-',
        ])
        ->assertStatus(404);
});

it('LOAD-BEARING: DB CHECK constraint rejects a raw INSERT with auto-gen=true and prefix=null', function (): void {
    // INSERT path companion to the UPDATE path test below. Together
    // they pin the third defensive layer: the composite CHECK fires on
    // either write path. Same regression-protection shape as the
    // leave_requests day_part single-date check.
    //
    // Fresh tenant+company so we have a clean slate; the bootstrap
    // listener will have already created a default settings row for
    // the new company, so we delete that first to avoid tripping the
    // unique index before the CHECK.
    $tenant = Tenant::factory()->create();
    $company = Company::factory()->forTenant($tenant)->create();
    DB::table('hrm_settings')->where('company_id', $company->id)->delete();

    $thrown = false;
    try {
        DB::table('hrm_settings')->insert([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'auto_generate_employee_code' => true, // ← inconsistent with prefix below
            'employee_code_prefix' => null,
            'default_employee_status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $thrown = true;
        expect($e->getMessage())->toContain('hrm_settings_autogen_prefix_consistency_check');
    }

    expect($thrown)->toBeTrue(
        'Expected the composite CHECK constraint to reject the inconsistent raw INSERT.',
    );
});

it('LOAD-BEARING: DB CHECK constraint rejects a raw UPDATE with auto-gen=true and prefix=null', function (): void {
    // Bypass the model + FormRequest entirely — raw DB::table()->update()
    // skips both layers. This proves the composite CHECK fires regardless
    // of application-layer validation. Same regression-protection pattern
    // as the leave_requests day_part single-date check test.
    $thrown = false;
    try {
        DB::table('hrm_settings')
            ->where('id', $this->settings->id)
            ->update([
                'auto_generate_employee_code' => true,
                'employee_code_prefix' => null,
            ]);
    } catch (QueryException $e) {
        $thrown = true;
        expect($e->getMessage())->toContain('hrm_settings_autogen_prefix_consistency_check');
    }

    expect($thrown)->toBeTrue(
        'Expected the composite CHECK constraint to reject the inconsistent raw UPDATE.',
    );
});
