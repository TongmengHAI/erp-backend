<?php

declare(strict_types=1);

namespace App\Domain\HRM\Actions;

use App\Domain\HRM\Models\HrmSettings;
use Illuminate\Support\Facades\DB;

/**
 * Update the HRM settings row for a company. No Create action — every
 * company has a settings row via the BootstrapHrmSettingsListener +
 * migration backfill, so PATCH is the only mutation flow.
 *
 * Diff-only audit row is produced by the Auditable trait. Cross-field
 * consistency (prefix required when auto-gen is on) is enforced at the
 * FormRequest layer + the DB CHECK constraint; this Action trusts the
 * pre-validated payload.
 */
final class UpdateHrmSettingsAction
{
    /**
     * @param  array{
     *     auto_generate_employee_code?: bool,
     *     employee_code_prefix?: string|null,
     *     default_employee_status?: string,
     * }  $data
     */
    public function execute(HrmSettings $settings, array $data): HrmSettings
    {
        return DB::transaction(function () use ($settings, $data): HrmSettings {
            $settings->fill($data);
            $settings->save();

            return $settings->refresh();
        });
    }
}
