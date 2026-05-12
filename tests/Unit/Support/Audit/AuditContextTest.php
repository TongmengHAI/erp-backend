<?php

declare(strict_types=1);

use App\Support\Audit\AuditContext;

it('fromCurrentRequest captures the IP, user-agent, and generates a request_id', function (): void {
    $context = AuditContext::fromCurrentRequest();

    expect($context->ip)->toBe('127.0.0.1');
    expect($context->userAgent)->toBe('Symfony');
    expect($context->requestId)->not->toBeEmpty();
});

it('asSystem returns a context with null IP and user_agent and a fresh request_id', function (): void {
    $context = AuditContext::asSystem();

    expect($context->ip)->toBeNull();
    expect($context->userAgent)->toBeNull();
    expect($context->requestId)->not->toBeEmpty();
});
