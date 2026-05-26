<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// Migration ↔ Calculator equivalence — the load-bearing discipline
// that lets the migration's raw SQL backfill expression and the
// LeaveDaysCalculator PHP class coexist safely.
//
// The micro-slice's migration backfills existing rows via raw SQL.
// Going forward, every new/updated row routes through the Calculator.
// If the two expressions drift, rows backfilled at migration time
// would carry a DIFFERENT value than rows updated post-migration —
// silent data corruption for the downstream Leave Balances slice
// (which aggregates days_count via SUM).
//
// This test seeds rows that cover every shape the migration's CASE
// statement reaches, asserts the calculator produces the same number
// for each, and treats any divergence as a test failure rather than
// a runtime surprise.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Enums\DayPart;
use App\Domain\HRM\Support\LeaveDaysCalculator;

it('LOAD-BEARING: migration backfill SQL and LeaveDaysCalculator agree on every shape', function (): void {
    $calc = new LeaveDaysCalculator;

    $cases = [
        // [start, end, day_part, expected]
        ['2026-06-01', '2026-06-01', DayPart::FullDay,   1.0],   // single-day full
        ['2026-06-01', '2026-06-05', DayPart::FullDay,   5.0],   // multi-day full
        ['2024-02-28', '2024-03-01', DayPart::FullDay,   3.0],   // leap year boundary
        ['2026-12-30', '2027-01-02', DayPart::FullDay,   4.0],   // year-end span
        ['2026-06-15', '2026-06-15', DayPart::Morning,   0.5],   // half-day AM
        ['2026-06-15', '2026-06-15', DayPart::Afternoon, 0.5],   // half-day PM
    ];

    foreach ($cases as [$start, $end, $part, $expected]) {
        // 1. Calculator says…
        $calculatorResult = $calc->compute($start, $end, $part);

        // 2. Migration backfill SQL says… (executed via SELECT on raw
        //    literals so we can run it in isolation without touching
        //    the actual leave_requests table).
        $row = DB::selectOne(
            "SELECT CASE
                 WHEN ?::varchar IN ('morning','afternoon') THEN 0.5
                 ELSE ((?::date - ?::date) + 1)::numeric(5,1)
             END AS days_count",
            [$part->value, $end, $start],
        );
        $sqlResult = (float) $row->days_count;

        // 3. Both must match for the seed row's shape.
        expect($calculatorResult)->toBe(
            $expected,
            sprintf('Calculator wrong for %s..%s %s', $start, $end, $part->value),
        );
        expect($sqlResult)->toBe(
            $expected,
            sprintf('Backfill SQL wrong for %s..%s %s', $start, $end, $part->value),
        );
        expect($calculatorResult)->toBe(
            $sqlResult,
            sprintf('Calculator/SQL divergence for %s..%s %s', $start, $end, $part->value),
        );
    }
});
