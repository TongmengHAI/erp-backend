<?php

declare(strict_types=1);

namespace App\Support\Audit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent rollover command. Creates the next N months of monthly
 * partitions for audit_logs if they don't already exist. Scheduled
 * monthly with N=3 — see routes/console.php.
 *
 * Designed for "future" partitions only. Backfilling historical
 * partitions for archived data is a separate operation handled outside
 * the schedule.
 *
 * If the schedule lapses for ≥ N months, audit_logs INSERTs start failing
 * loud (no covering partition). The migration ships 14 months of initial
 * partitions, so a fresh install can survive about a year of scheduler
 * downtime — but production cron MUST be running by then. See
 * docs/runbooks/audit-partition-maintenance.md.
 */
final class CreateAuditPartitionsCommand extends Command
{
    /** @var string */
    protected $signature = 'audit:partitions:rollover {--months=3 : how many months ahead to ensure exist}';

    /** @var string */
    protected $description = 'Create future monthly partitions for audit_logs (idempotent).';

    public function handle(): int
    {
        $months = (int) $this->option('months');
        if ($months < 1) {
            $this->error('--months must be >= 1');

            return self::FAILURE;
        }

        $created = 0;
        $skipped = 0;

        $startOfThisMonth = now()->startOfMonth();
        for ($i = 0; $i <= $months; $i++) {
            $monthStart = $startOfThisMonth->copy()->addMonthsNoOverflow($i)->startOfMonth();
            $monthEnd = $monthStart->copy()->addMonthNoOverflow()->startOfMonth();
            $name = 'audit_logs_'.$monthStart->format('Y_m');

            if ($this->partitionExists($name)) {
                $skipped++;

                continue;
            }

            DB::statement(sprintf(
                "CREATE TABLE %s PARTITION OF audit_logs FOR VALUES FROM ('%s') TO ('%s')",
                $name,
                $monthStart->toDateString(),
                $monthEnd->toDateString(),
            ));

            $this->info(sprintf('Created partition %s [%s, %s)', $name, $monthStart->toDateString(), $monthEnd->toDateString()));
            $created++;
        }

        $this->info(sprintf('Rollover complete — created %d, already existed %d.', $created, $skipped));

        return self::SUCCESS;
    }

    /**
     * Query pg_inherits instead of pg_class to be unambiguous: only
     * partitions of audit_logs (not random tables that happen to have
     * a name like audit_logs_2099_01) count.
     */
    private function partitionExists(string $partitionName): bool
    {
        $result = DB::select(<<<'SQL'
            SELECT 1
            FROM pg_inherits i
            JOIN pg_class child  ON child.oid  = i.inhrelid
            JOIN pg_class parent ON parent.oid = i.inhparent
            WHERE parent.relname = 'audit_logs'
              AND child.relname  = ?
        SQL, [$partitionName]);

        return $result !== [];
    }
}
