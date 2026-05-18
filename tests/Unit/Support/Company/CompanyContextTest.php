<?php

declare(strict_types=1);

use App\Models\Company;
use App\Support\Company\CompanyContext;
use App\Support\Company\Exceptions\CompanyContextMissingException;

function fakeCompany(int $id = 1): Company
{
    $c = new Company(['slug' => 'company-'.$id, 'name' => 'Company '.$id]);
    $c->id = $id;
    $c->exists = true;

    return $c;
}

it('is null before set and populated after setCurrent', function (): void {
    $ctx = new CompanyContext;
    expect($ctx->current())->toBeNull();

    $company = fakeCompany(42);
    $ctx->setCurrent($company);

    expect($ctx->current())->toBe($company);
    expect($ctx->currentId())->toBe(42);
});

it('throws CompanyContextMissingException when currentId() is called without a company', function (): void {
    expect(fn () => (new CompanyContext)->currentId())
        ->toThrow(CompanyContextMissingException::class);
});

it('reports inAcrossCompaniesMode correctly inside and outside acrossCompanies', function (): void {
    $ctx = new CompanyContext;
    expect($ctx->inAcrossCompaniesMode())->toBeFalse();

    $insideFlag = null;
    $ctx->acrossCompanies(function () use ($ctx, &$insideFlag): void {
        $insideFlag = $ctx->inAcrossCompaniesMode();
    });

    expect($insideFlag)->toBeTrue();
    expect($ctx->inAcrossCompaniesMode())->toBeFalse();
});

it('acrossCompanies clears the company for the duration of the closure', function (): void {
    $ctx = new CompanyContext;
    $company = fakeCompany();
    $ctx->setCurrent($company);

    $insideCompany = 'sentinel';
    $ctx->acrossCompanies(function () use ($ctx, &$insideCompany): void {
        $insideCompany = $ctx->current();
    });

    expect($insideCompany)->toBeNull();
    expect($ctx->current())->toBe($company);
});

it('acrossCompanies restores the previous company even when the closure throws', function (): void {
    $ctx = new CompanyContext;
    $company = fakeCompany();
    $ctx->setCurrent($company);

    try {
        $ctx->acrossCompanies(function (): never {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($ctx->current())->toBe($company);
    expect($ctx->inAcrossCompaniesMode())->toBeFalse();
});

it('currentId inside acrossCompanies throws with developer-oriented message', function (): void {
    $ctx = new CompanyContext;
    $company = fakeCompany();
    $ctx->setCurrent($company);

    $caughtMessage = null;
    try {
        $ctx->acrossCompanies(function () use ($ctx): void {
            $ctx->currentId();
        });
    } catch (CompanyContextMissingException $e) {
        $caughtMessage = $e->getMessage();
    }

    expect($caughtMessage)
        ->toContain('called inside acrossCompanies()');
});

it('is registered as a scoped singleton in the container', function (): void {
    $a = app(CompanyContext::class);
    $b = app(CompanyContext::class);

    expect($a)->toBe($b);
});
