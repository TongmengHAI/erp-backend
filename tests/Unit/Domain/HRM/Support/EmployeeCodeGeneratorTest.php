<?php

declare(strict_types=1);

use App\Domain\HRM\Models\HrmEmployeeCodeSequence;
use App\Domain\HRM\Support\EmployeeCodeGenerator;
use App\Models\Company;
use App\Models\Tenant;
use App\Support\Company\CompanyContext;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->company = Company::factory()->forTenant($this->tenant)->create();
    app(TenantContext::class)->setCurrent($this->tenant);
    app(CompanyContext::class)->setCurrent($this->company);
    $this->gen = app(EmployeeCodeGenerator::class);
});

it('generates {prefix}1 on first call and {prefix}2 on second', function (): void {
    $first = DB::transaction(fn () => $this->gen->next($this->tenant->id, $this->company->id, 'TT-'));
    $second = DB::transaction(fn () => $this->gen->next($this->tenant->id, $this->company->id, 'TT-'));

    expect($first)->toBe('TT-1');
    expect($second)->toBe('TT-2');
});

it('lazily creates the sequence row on first use', function (): void {
    expect(HrmEmployeeCodeSequence::query()->withoutGlobalScopes()->count())->toBe(0);

    DB::transaction(fn () => $this->gen->next($this->tenant->id, $this->company->id, 'TT-'));

    expect(HrmEmployeeCodeSequence::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('throws InvalidArgumentException when prefix is null', function (): void {
    expect(fn () => DB::transaction(fn () => $this->gen->next($this->tenant->id, $this->company->id, null)))
        ->toThrow(InvalidArgumentException::class);
});

it('throws InvalidArgumentException when prefix is empty string', function (): void {
    expect(fn () => DB::transaction(fn () => $this->gen->next($this->tenant->id, $this->company->id, '')))
        ->toThrow(InvalidArgumentException::class);
});

// NOTE — the "throws RuntimeException when invoked outside
// DB::transaction" case is asserted in the generator code itself
// (DB::transactionLevel() === 0 guard) but is NOT covered by an
// automated test here: RefreshDatabase wraps every Pest test in a
// transaction, so transactionLevel is always > 0 within a Pest
// runtime. The guard is real defense for production code paths
// that invoke the generator outside a transaction (which would
// silently make SELECT FOR UPDATE a no-op and break the
// concurrency story). Documented, not tested.

it('LOAD-BEARING: separate companies have independent sequences', function (): void {
    $secondCompany = Company::factory()->forTenant($this->tenant)->create();

    $firstCo1 = DB::transaction(fn () => $this->gen->next($this->tenant->id, $this->company->id, 'TT-'));
    $firstCo2 = DB::transaction(fn () => $this->gen->next($this->tenant->id, $secondCompany->id, 'TT-'));

    // Each company starts at 1 — no cross-company counter leakage.
    expect($firstCo1)->toBe('TT-1');
    expect($firstCo2)->toBe('TT-1');
});
