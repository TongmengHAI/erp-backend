<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Concerns\Auditable;
use App\Support\Tenancy\Enums\TenantStatus;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $legal_name
 * @property string $country_code
 * @property string $default_currency
 * @property string $functional_currency
 * @property string $timezone
 * @property TenantStatus $status
 * @property array<string, mixed>|null $settings
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class Tenant extends Model
{
    use Auditable;

    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'slug',
        'name',
        'legal_name',
        'country_code',
        'default_currency',
        'functional_currency',
        'timezone',
        'status',
        'settings',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'settings' => 'array',
        ];
    }
}
