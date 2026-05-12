# ADR 0001: Record architecture decisions

- **Status:** Accepted
- **Date:** 2026-05-12

## Context

We need a lightweight way to capture architectural decisions — what was chosen, what alternatives were considered, and *why* — so that future contributors (and future-us) can understand the reasoning rather than re-litigating decisions every quarter.

## Decision

We will record significant architectural decisions as **Architecture Decision Records (ADRs)** in `docs/adr/`, numbered sequentially (`0001-...md`, `0002-...md`, ...).

Each ADR has the following structure:

- **Title** — `NNNN: short imperative description`
- **Status** — Proposed / Accepted / Superseded by ADR-NNNN / Deprecated
- **Date** — `YYYY-MM-DD`
- **Context** — what's the situation, what forces are at play
- **Decision** — what we're going to do
- **Consequences** — positive, negative, and follow-on implications
- (optional) **Alternatives considered** — and why we rejected them

ADRs are immutable once accepted; if a decision changes, write a new ADR that supersedes the old one and update the old one's status. Never edit history.

## Consequences

- Decisions become discoverable. New contributors can read the `docs/adr/` directory in order to understand how the system came to be.
- Locked decisions in `CLAUDE.md` (§2 tech stack, §4 accounting design) are summarized; the *reasoning* for each lives in its own ADR.
- Slight overhead per non-trivial decision (~15 minutes to write).

## Alternatives considered

- **Wiki / Confluence / Notion** — rejected. Decisions drift away from the code; nobody reads them; permissions become a nightmare.
- **No formal record** — rejected. We tried this in past projects; institutional memory rots within 6 months.
