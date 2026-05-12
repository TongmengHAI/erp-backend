<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditContext;

it('fromCurrentRequest captures the authenticated user, IP, user-agent, and a request_id', function (): void {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->forTenant($tenant)->create();
    $this->actingAs($user);

    $context = AuditContext::fromCurrentRequest();

    expect($context->actorId)->toBe($user->id);
    expect($context->actorType)->toBe(User::class);
    expect($context->ip)->toBe('127.0.0.1');
    expect($context->userAgent)->toBe('Symfony');
    expect($context->requestId)->not->toBeEmpty();
});

it('asSystem returns a context with actor_type=system and null actor/ip/user_agent', function (): void {
    $context = AuditContext::asSystem();

    expect($context->actorId)->toBeNull();
    expect($context->actorType)->toBe('system');
    expect($context->ip)->toBeNull();
    expect($context->userAgent)->toBeNull();
    expect($context->requestId)->not->toBeEmpty();
});
