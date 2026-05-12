<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Request-scoped snapshot of WHERE the audited operation came from (ip,
 * user_agent, request_id).
 *
 * Notably DOES NOT carry the actor. Actor is resolved at AuditWriter time
 * via Auth::user() so that audit rows written before vs. after actingAs()
 * in a request lifecycle each capture the correct user — important for
 * tests where setUp builds entities before authenticating.
 *
 * Bound as scoped() in AppServiceProvider — one instance per request, lazily
 * resolved on first access. request_id is generated once and reused for every
 * audit row in the same request (correlation).
 */
final readonly class AuditContext
{
    public function __construct(
        public ?string $ip,
        public ?string $userAgent,
        public string $requestId,
    ) {}

    public static function fromCurrentRequest(): self
    {
        $request = app(Request::class);

        $userAgent = $request->userAgent();

        return new self(
            ip: $request->ip(),
            userAgent: $userAgent === null ? null : Str::substr($userAgent, 0, 500),
            requestId: $request->headers->get('X-Request-ID') ?? Str::uuid()->toString(),
        );
    }

    /**
     * Context for non-request operations: seeders, queued jobs, console
     * commands. Used when an explicit "system" attribution is desired.
     */
    public static function asSystem(): self
    {
        return new self(
            ip: null,
            userAgent: null,
            requestId: Str::uuid()->toString(),
        );
    }
}
