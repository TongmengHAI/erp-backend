<?php

declare(strict_types=1);

namespace App\Domain\HRM\Models;

use App\Domain\HRM\Enums\DepartmentStatus;
use App\Support\Audit\Concerns\Auditable;
use App\Support\Company\Concerns\BelongsToCompany;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\HRM\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property DepartmentStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class Department extends Model
{
    use Auditable;
    use BelongsToCompany;
    use BelongsToTenant;

    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'name',
        'description',
        'status',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => DepartmentStatus::class,
        ];
    }

    protected static function newFactory(): DepartmentFactory
    {
        return DepartmentFactory::new();
    }
}
