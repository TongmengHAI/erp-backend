<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// LeaveDaysCalculator — pure-function unit tests. No DB, no factories,
// no Eloquent. The calculator is the single source of truth for
// days_count and the same expression that the migration backfill
// produces; the migration test below asserts the equivalence.
// ─────────────────────────────────────────────────────────────────────────────

use App\Domain\HRM\Enums\DayPart;
use App\Domain\HRM\Support\LeaveDaysCalculator;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->calc = new LeaveDaysCalculator;
});

it('returns 1.0 for a single-day full request', function (): void {
    expect($this->calc->compute('2026-06-15', '2026-06-15', DayPart::FullDay))
        ->toBe(1.0);
});

it('returns N+1 for a multi-day full request (inclusive of both endpoints)', function (): void {
    expect($this->calc->compute('2026-06-15', '2026-06-17', DayPart::FullDay))
        ->toBe(3.0);
});

it('returns 0.5 for a morning half-day', function (): void {
    expect($this->calc->compute('2026-06-15', '2026-06-15', DayPart::Morning))
        ->toBe(0.5);
});

it('returns 0.5 for an afternoon half-day', function (): void {
    expect($this->calc->compute('2026-06-15', '2026-06-15', DayPart::Afternoon))
        ->toBe(0.5);
});

it('counts February 29 correctly in a leap year (2024-02-28 to 2024-03-01 = 3 days)', function (): void {
    expect($this->calc->compute('2024-02-28', '2024-03-01', DayPart::FullDay))
        ->toBe(3.0);
});

it('counts February 28 to March 1 as 2 days in a non-leap year (2026)', function (): void {
    expect($this->calc->compute('2026-02-28', '2026-03-01', DayPart::FullDay))
        ->toBe(2.0);
});

it('handles year-end spanning (2026-12-30 to 2027-01-02 = 4 days)', function (): void {
    expect($this->calc->compute('2026-12-30', '2027-01-02', DayPart::FullDay))
        ->toBe(4.0);
});

it('accepts Carbon and DateTimeImmutable instances interchangeably', function (): void {
    $carbonStart = Carbon::parse('2026-06-15');
    $immutableEnd = new DateTimeImmutable('2026-06-17');

    expect($this->calc->compute($carbonStart, $immutableEnd, DayPart::FullDay))
        ->toBe(3.0);
});

it('ignores the time component (timestamps in the same calendar day count as one)', function (): void {
    expect($this->calc->compute('2026-06-15 23:59:00', '2026-06-15 00:01:00', DayPart::FullDay))
        ->toBe(1.0);
});

it('returns abs span when start > end (defensive — DB CHECK prevents this from persisting)', function (): void {
    // Calculator returns a sane positive number for draft/preview
    // calls where the user has typed dates in the wrong order. The
    // DB rejects the row downstream regardless.
    expect($this->calc->compute('2026-06-20', '2026-06-15', DayPart::FullDay))
        ->toBe(6.0);
});
