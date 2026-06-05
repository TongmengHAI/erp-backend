<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\InvitationStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\Concerns\Auditable;
use App\Support\Tenancy\Concerns\BelongsToTenant;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $email
 * @property string|null $name
 * @property int $role_id
 * @property string $token_hash
 * @property int $invited_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_user_id
 * @property Carbon|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 *
 * Computed status (NOT a column) — see InvitationStatus enum + the
 * status() accessor below. The InvitationQueryService selects the
 * equivalent SQL CASE WHEN for indexable filtering.
 */
final class Invitation extends Model
{
    use Auditable;
    use BelongsToTenant;

    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * Token storage uses **deterministic SHA-256**, not BCrypt.
     *
     * The accept URL is /invitations/{token} — only the raw token is
     * present in the request. There is no second identifier (no email,
     * no tenant slug) to narrow the lookup, so the hash function must
     * be deterministic to make `WHERE token_hash = ?` an indexable
     * O(log n) lookup. BCrypt is salted; same input → different hash
     * each call → useless for equality lookups.
     *
     * The slow-hash benefit of BCrypt protects LOW-ENTROPY passwords
     * from offline brute force. Our tokens are Str::random(43) —
     * 256 bits of entropy from the CSPRNG. A SHA-256 hash of a
     * 256-bit random value is just as resistant to offline attack
     * as the source — there is nothing for the attacker to brute-
     * force their way through. GitHub, Stripe, Sentry all use this
     * pattern for API tokens / webhook signing tokens / similar.
     *
     * The migration's column type (varchar(255)) accommodates the
     * 64-char SHA-256 hex output trivially; no schema change was
     * needed for this pivot from the original Q1 BCrypt lean.
     */
    public const TOKEN_BYTES = 32;        // → Str::random(43) URL-safe base64

    public static function generateRawToken(): string
    {
        return Str::random(43);
    }

    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Explicit factory class override. Laravel's auto-resolver maps
     * App\Domain\Identity\Models\Invitation → Database\Factories\
     * Domain\Identity\Models\InvitationFactory, mirroring the model's
     * namespace under app/. We keep factories flat under
     * database/factories/, so the override points at the actual
     * location.
     */
    protected static function newFactory(): InvitationFactory
    {
        return InvitationFactory::new();
    }

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'email',
        'name',
        'role_id',
        'token_hash',
        'invited_by_user_id',
        'expires_at',
        'accepted_at',
        'accepted_user_id',
        'cancelled_at',
        'cancelled_by_user_id',
    ];

    /** Hide the token hash from any accidental JSON serialization. */
    /** @var list<string> */
    protected $hidden = [
        'token_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * Computed status. Resolution order is history-wins:
     * accepted > cancelled > expired > pending.
     *
     * The InvitationQueryService mirrors this exact ordering in SQL —
     * any drift between the two surfaces breaks status filter results.
     * Keep them in sync.
     */
    public function status(): InvitationStatus
    {
        if ($this->accepted_at !== null) {
            return InvitationStatus::Accepted;
        }
        if ($this->cancelled_at !== null) {
            return InvitationStatus::Cancelled;
        }
        if ($this->expires_at->isPast()) {
            return InvitationStatus::Expired;
        }

        return InvitationStatus::Pending;
    }

    public function isPending(): bool
    {
        return $this->status() === InvitationStatus::Pending;
    }
}
