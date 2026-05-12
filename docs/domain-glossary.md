# Domain Glossary

Terms used throughout the codebase. When in doubt, default to these definitions.

| Term | Definition |
|---|---|
| **COA** | Chart of Accounts. Hierarchical tree of accounts; only leaf accounts are postable. |
| **GL** | General Ledger. The set of all posted journal entries. |
| **JE** | Journal Entry. A balanced (Σdebits = Σcredits) double-entry bookkeeping record. |
| **GRN** | Goods Receipt Note. Records the physical receipt of purchased goods, regardless of invoice. |
| **GR-IR** | Goods Received Not Invoiced. Clearing account that bridges GRN (Dr Inventory / Cr GR-IR) and supplier invoice (Dr GR-IR / Cr AP). |
| **AR** / **AP** | Accounts Receivable / Accounts Payable. Sub-ledger control accounts. |
| **PR** / **PO** | Purchase Requisition / Purchase Order. |
| **SO** | Sales Order. |
| **FX** | Foreign Exchange. |
| **NBC** | National Bank of Cambodia — source of official daily FX rates. |
| **DDD** | Domain-Driven Design. |
| **Functional currency** | The currency a tenant keeps its books in. Set per tenant. |
| **Transaction currency** | The currency in which a specific event (invoice, payment, etc.) actually occurred. |
| **Reporting currency** | An optional third currency for consolidated reports — deferred (computed at period end if/when needed). |
| **Soft close** | Period state where posting is blocked by default but a permission + override can allow it. Used during month-end close. |
| **Hard close** | Period state where posting is impossible. Corrections go to the current open period. |
| **Reopened** | Rare audit-flagged state. Requires permission + written reason. |
| **Idempotency key** | `(source_type, source_id, tenant_id)` UNIQUE on `journal_entries`. Prevents double-posting on retried events. |
| **Tenant scope** | Global Eloquent scope (`BelongsToTenant`) that auto-filters every query by current tenant. |
| **Action** | Single-purpose class with one public `execute()` method. E.g. `PostJournalEntryAction`. |
| **Service** | Stateful or multi-method class. E.g. `LedgerPostingService`. |
| **Event** | Past-tense name (`JournalEntryPosted`). Cross-domain communication mechanism. |
| **Listener** | `VerbNounListener`. Subscribes to events from other domains. |
