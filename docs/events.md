# Domain Events Catalog

Every cross-domain event the application emits is listed here. Events are the **only** way domains communicate.

| Event | Emitted by | Payload (key fields) | Listened to by | Purpose |
|---|---|---|---|---|
| _none yet — populate as features land_ | | | | |

## Conventions

- Events are past-tense (`JournalEntryPosted`, not `PostJournalEntry`).
- Events live in `app/Domain/<EmittingDomain>/Events/`.
- Listeners live in `app/Domain/<ReceivingDomain>/Listeners/` — never in the emitting domain.
- Cross-domain events fire via `DB::afterCommit()` or as queued listeners — never inside an uncommitted transaction.
- Listeners on the `accounting` queue are responsible for posting derived journal entries; failures land in `failed_journal_postings`.

## Adding a new event

1. Create the event class under `app/Domain/<X>/Events/`.
2. Document it in this table — emitting domain, payload shape, expected listeners.
3. Wire listeners in `app/Providers/DomainEventServiceProvider.php`.
4. Add an integration test under `tests/Integration/` that asserts the full flow.
