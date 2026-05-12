<?php

declare(strict_types=1);

namespace App\Support\Audit\Concerns;

use App\Support\Audit\Exceptions\AuditConfigurationException;
use App\Support\Audit\Services\AuditWriter;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Apply to every Eloquent model whose changes need an audit trail.
 *
 * Maps Eloquent lifecycle events to audit action verbs as follows:
 *
 *   created                                              → action='created'
 *   deleted, isForceDeleting=false (SoftDeletes only)    → action='soft_deleted'
 *   deleted, isForceDeleting=true OR no SoftDeletes      → action='hard_deleted'
 *   updated, only deleted_at went timestamp → null       → action='restored'
 *   updated, anything else                                → action='updated'
 *
 *   Soft-delete uses Eloquent's runSoftDelete which writes via a RAW DB query
 *   (no save() → no `updated` event fires). Only `deleted` fires. The deleted
 *   handler inspects isForceDeleting() to disambiguate soft vs hard.
 *
 *   Restore uses save() which DOES fire `updated`. We do NOT hook the
 *   `restored` event itself because by then Eloquent has synced original
 *   attributes, losing the previous deleted_at value. Catching the transition
 *   in `updated` (before finishSave's syncOriginal runs) preserves the diff.
 *
 * Field filtering — override two methods (NOT properties — trait property
 * compatibility rules make property overrides fragile):
 *
 *   protected function auditOnly(): ?array   — allowlist; if set, ONLY these
 *                                              fields are audited
 *   protected function auditExcept(): ?array — denylist; these fields are
 *                                              excluded ON TOP of defaults
 *
 * Default exclusions (always applied unless auditOnly() overrides):
 *   - 'updated_at' (noisy, redundant with audit_logs.created_at)
 *   - every key in $hidden (passwords, tokens — never audited)
 *
 * Setting BOTH at once throws AuditConfigurationException on first audit
 * write for that class (fail loud, §G). Checked lazily, once per class,
 * via a static cache — avoids `new static()` which is unsafe in traits
 * (we don't know subclass constructors).
 *
 * Audit writes are synchronous and inside the parent transaction. Failure
 * throws AuditWriteFailedException and rolls back the business write.
 */
trait Auditable
{
    /**
     * Classes whose audit configuration has been validated. Populated lazily
     * on first audit write per class. Avoids re-validating on every write.
     *
     * @var array<class-string, true>
     */
    private static array $auditConfigCheckedClasses = [];

    public static function bootAuditable(): void
    {
        // Intentionally no `restored` hook — see class docblock. Restore is
        // detected inside writeAuditOnUpdated before Eloquent's finishSave
        // syncs the original attributes and loses the previous deleted_at value.
        // @phpstan-ignore method.notFound (trait methods on Model — analyzer can't see them)
        static::created(static fn (Model $m) => $m->writeAuditOnCreated());
        // @phpstan-ignore method.notFound
        static::updated(static fn (Model $m) => $m->writeAuditOnUpdated());
        // @phpstan-ignore method.notFound
        static::deleted(static fn (Model $m) => $m->writeAuditOnDeleted());
    }

    /**
     * Override to declare an allowlist of fields to audit.
     * Mutually exclusive with auditExcept() — see boot check.
     *
     * @return list<string>|null
     */
    protected function auditOnly(): ?array
    {
        return null;
    }

    /**
     * Override to declare a denylist of fields to exclude from audit
     * (extends the default exclusions: 'updated_at' + $hidden).
     * Mutually exclusive with auditOnly() — see boot check.
     *
     * @return list<string>|null
     */
    protected function auditExcept(): ?array
    {
        return null;
    }

    public function writeAuditOnCreated(): void
    {
        $this->assertAuditConfigurationOnce();

        AuditWriter::record(
            model: $this,
            action: 'created',
            before: null,
            after: $this->filterAttributesForAudit($this->getAttributes()),
        );
    }

    public function writeAuditOnUpdated(): void
    {
        $this->assertAuditConfigurationOnce();

        $dirty = $this->getDirty();
        if ($dirty === []) {
            return;
        }

        // Restore detection — deleted_at went from timestamp → null and that's
        // the only dirty field. Catch BEFORE finishSave syncs original.
        // Soft-delete does NOT route here (runSoftDelete uses a raw query and
        // never fires `updated`); it's handled in writeAuditOnDeleted instead.
        if (count($dirty) === 1 && array_key_exists('deleted_at', $dirty)) {
            $previous = $this->getOriginal('deleted_at');
            $current = $dirty['deleted_at'];

            if ($previous !== null && $current === null) {
                AuditWriter::record(
                    model: $this,
                    action: 'restored',
                    before: ['deleted_at' => $this->castDeletedAt($previous)],
                    after: ['deleted_at' => null],
                );

                return;
            }
        }

        // Regular update — diff-only before/after
        $beforeRaw = [];
        $afterRaw = [];
        foreach ($dirty as $key => $newValue) {
            $beforeRaw[$key] = $this->getOriginal($key);
            $afterRaw[$key] = $newValue;
        }

        $before = $this->filterAttributesForAudit($beforeRaw);
        $after = $this->filterAttributesForAudit($afterRaw);

        if ($after === []) {
            return;
        }

        AuditWriter::record(
            model: $this,
            action: 'updated',
            before: $before,
            after: $after,
        );
    }

    public function writeAuditOnDeleted(): void
    {
        $this->assertAuditConfigurationOnce();

        $usesSoftDeletes = in_array(SoftDeletes::class, class_uses_recursive(static::class), true);

        if ($usesSoftDeletes) {
            // isForceDeleting() comes from the SoftDeletes trait — only callable
            // when the using class actually has that trait, which the runtime
            // check above guarantees. PHPStan analyses the trait in isolation
            // and can't see that constraint.
            // @phpstan-ignore method.notFound
            $isForceDeleting = $this->isForceDeleting();
            if (! $isForceDeleting) {
                $deletedAt = $this->getAttribute('deleted_at');
                AuditWriter::record(
                    model: $this,
                    action: 'soft_deleted',
                    before: ['deleted_at' => null],
                    after: ['deleted_at' => $this->castDeletedAt($deletedAt)],
                );

                return;
            }
        }

        // Hard delete — capture full filtered state because there is no "diff."
        AuditWriter::record(
            model: $this,
            action: 'hard_deleted',
            before: $this->filterAttributesForAudit($this->getOriginal()),
            after: null,
        );
    }

    /**
     * Apply auditOnly() / auditExcept() + default exclusions to an attribute array.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function filterAttributesForAudit(array $attributes): array
    {
        $only = $this->auditOnly();
        if ($only !== null) {
            return array_intersect_key($attributes, array_flip($only));
        }

        $exclude = array_unique(array_merge(
            ['updated_at'],
            $this->getHidden(),
            $this->auditExcept() ?? [],
        ));

        return array_diff_key($attributes, array_flip($exclude));
    }

    /**
     * Check the configuration once per class. Cheap second/Nth call (array lookup).
     * Throws if auditOnly() AND auditExcept() are both set — boot would be
     * preferable but `new static()` is unsafe in traits per PHPStan, and we
     * need an instance to invoke the override methods.
     */
    private function assertAuditConfigurationOnce(): void
    {
        $class = static::class;
        if (isset(self::$auditConfigCheckedClasses[$class])) {
            return;
        }

        if ($this->auditOnly() !== null && $this->auditExcept() !== null) {
            throw new AuditConfigurationException($class);
        }

        self::$auditConfigCheckedClasses[$class] = true;
    }

    /**
     * Normalise a deleted_at value (Carbon|string|null) to an ISO 8601 string
     * for JSONB storage. The value can arrive as Carbon (already cast) or
     * string (pre-save), depending on which Eloquent event fired.
     */
    private function castDeletedAt(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        return (string) $value;
    }
}
