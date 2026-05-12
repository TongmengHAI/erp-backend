<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\Tenancy\Exceptions\TenantContextMissingException;
use App\Support\Tenancy\TenantContext;

function fakeTenant(int $id = 1): Tenant
{
    $t = new Tenant(['slug' => 'tenant-'.$id, 'name' => 'Tenant '.$id]);
    $t->id = $id;
    $t->exists = true;

    return $t;
}

it('is null before set and populated after setCurrent', function (): void {
    $ctx = new TenantContext;
    expect($ctx->current())->toBeNull();

    $tenant = fakeTenant(42);
    $ctx->setCurrent($tenant);

    expect($ctx->current())->toBe($tenant);
    expect($ctx->currentId())->toBe(42);
});

it('throws TenantContextMissingException when currentId() is called without a tenant', function (): void {
    expect(fn () => (new TenantContext)->currentId())
        ->toThrow(TenantContextMissingException::class);
});

it('reports inSystemMode correctly inside and outside asSystem', function (): void {
    $ctx = new TenantContext;
    expect($ctx->inSystemMode())->toBeFalse();

    $insideFlag = null;
    $ctx->asSystem(function () use ($ctx, &$insideFlag): void {
        $insideFlag = $ctx->inSystemMode();
    });

    expect($insideFlag)->toBeTrue();
    expect($ctx->inSystemMode())->toBeFalse();
});

it('asSystem clears the tenant for the duration of the closure', function (): void {
    $ctx = new TenantContext;
    $tenant = fakeTenant();
    $ctx->setCurrent($tenant);

    $insideTenant = 'sentinel';
    $ctx->asSystem(function () use ($ctx, &$insideTenant): void {
        $insideTenant = $ctx->current();
    });

    expect($insideTenant)->toBeNull();
    expect($ctx->current())->toBe($tenant);
});

it('asSystem restores the previous tenant even when the closure throws', function (): void {
    $ctx = new TenantContext;
    $tenant = fakeTenant();
    $ctx->setCurrent($tenant);

    try {
        $ctx->asSystem(function (): never {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect($ctx->current())->toBe($tenant);
    expect($ctx->inSystemMode())->toBeFalse();
});

it('is registered as a scoped singleton in the container', function (): void {
    $a = app(TenantContext::class);
    $b = app(TenantContext::class);

    expect($a)->toBe($b);
});
