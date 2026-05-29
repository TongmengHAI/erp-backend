<?php

declare(strict_types=1);

namespace App\Support\Company\Events;

use App\Models\Company;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a Company row is inserted. Dispatched from the Company
 * model's `booted()` hook via `static::created()`.
 *
 * Domain listeners subscribe to bootstrap per-company state:
 *   • BootstrapHrmSettingsListener — creates the default hrm_settings row
 *   • (future) BootstrapAccountingChartListener — copies the country COA template
 *   • (future) BootstrapInventoryDefaultsListener — etc.
 *
 * The event itself is domain-neutral (lives in Support/Company); the
 * listeners live in their own domains so each module owns its bootstrap
 * concern without the company-creation path knowing about every module.
 *
 * The Company model dispatches via the Eloquent `created` model event —
 * Laravel queues the dispatch until after the row is persisted. The
 * listener creates dependent rows in the same request (synchronous
 * listener; not queued). This works because:
 *
 *   1. The companies INSERT is already committed by the time `created`
 *      fires (Eloquent's model events fire after save).
 *   2. The settings/etc. rows that listeners create reference the
 *      company_id, which is now stable.
 *   3. If a listener throws, the company row is already there — the
 *      bootstrap failure surfaces as a 500, the user retries, and the
 *      listener's own firstOrCreate/idempotency handles the half-finished
 *      state on retry.
 */
final class CompanyCreated
{
    use Dispatchable;

    public function __construct(public readonly Company $company) {}
}
