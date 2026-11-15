# Adopt ADR-063 declarative MCP for Dossiq

## Why

Dossiq exposes its MCP surface through a hand-written `DossiqToolProvider` (2 tools: `dossiq.listProcesses`, `dossiq.getProcessDetails`). ADR-063 (hydra #102) makes OpenRegister the single MCP registry: schema-declared CRUD via the `x-openregister-mcp` dialect, plus `#[McpTool]` on service methods for genuine non-CRUD behaviour. Apps MUST NOT hand-write MCP tool code. Worse, a hand-written tool takes **precedence** over its derived twin — so as long as `DossiqToolProvider` lives, any dialect Dossiq declares on `case` is permanently shadowed and inert.

Dossiq is the largest schema owner in the fleet: **155 schema slugs** across `dossiq_register.json` (83), `ori_register.json` (6) and 18 `register.d/` fragments (66). Naive exposure would emit ~775 tools — precisely the tool-explosion ADR-063's default-OFF exists to prevent (measured ~9.5% LLM accuracy degradation and 30k+ tokens of context burn, Specter research). The whole job of this change is therefore **curation**: pick the small set of entities an agent would genuinely be asked about, and say why everything else stays off.

## What Changes

- Declare `x-openregister-mcp` (`enabled: true`) on **13 curated schemas** of 155 — `case`, `task`, `caseType`, `statusType`, `statusRecord`, `decision`, `result`, `resultType`, `document`, `caseDocument`, `bezwaar`, `termijnInstance`, `complaint` — yielding **22 derived tools**. All other 142 schemas stay OFF (default). Rationale per inclusion and per exclusion group lives in `design.md`.
- **Read-only.** Every declared verb is `search` or `get`, `scope: read`, `readOnlyHint: true`. **Zero write verbs** — no `create`, no `update`, no `delete` on any Dossiq schema. A derived write goes straight to `ObjectService`, bypassing the state machine, the mandate check, the parafering route and the Archiefwet retention rules that make a Dossiq write lawful. `design.md` records each refusal.
- **BREAKING (MCP surface):** `dossiq.listProcesses` and `dossiq.getProcessDetails` are REMOVED. They are replaced by the derived `dossiq.case.search` / `dossiq.case.get` / `dossiq.statusRecord.search` tools. Tool ids change; the AI Chat Companion picks the new ones up from the registry with no client change.
- **BREAKING (access model):** `DossiqToolProvider::canReadCase()` — an MCP-only assignee/role/admin ACL — disappears with the class. OpenRegister RBAC becomes the single gate, exactly as it already is for Dossiq's own UI (which reads cases straight from `/apps/openregister/api/objects`). See `design.md` § Access model.
- `lib/Mcp/DossiqToolProvider.php` is DELETED (both its tools are derivable CRUD; nothing survives), along with its `mcpProvider` registration in `lib/AppInfo/Application.php`, its unit test, and the `IMcpToolProvider` test stub / psalm suppression. No `IMcpScannableServices` opt-in is added — there is no surviving curated tool to scan for, and an empty scannable-services class would be a dead seam.

## Capabilities

### New Capabilities

_None._ The MCP surface is an existing capability; this change replaces how it is produced.

### Modified Capabilities

- `mcp-integration`: REQ-001 … REQ-005 (hand-written `IMcpToolProvider`, the 2-tool catalogue, the in-provider per-object ACL, the bespoke error envelope) are REMOVED and replaced by declarative requirements — the curated dialect set, the read-only posture, the write-verb refusal, and the RBAC delegation to OpenRegister.

## Impact

- **Schemas / registers:** `lib/Settings/dossiq_register.json` (11 schemas), `lib/Settings/register.d/60-termijnbewaking.json` (`termijnInstance`), `lib/Settings/register.d/70-document-zaakdossier.json` (nothing — see design). Each gets an `x-openregister-mcp` block inside the schema's `configuration` object (the location OpenRegister's `SchemaDerivedToolProvider::mcpAnnotation()` reads).
- **PHP:** `lib/Mcp/DossiqToolProvider.php` (deleted), `lib/AppInfo/Application.php` (`mcpProvider` key + `use` removed), `tests/Unit/Mcp/DossiqToolProviderTest.php` (deleted), `tests/Stubs/Mcp/IMcpToolProvider.php` + `tests/bootstrap.php` + `psalm.xml` (stub references removed).
- **Consumers:** Hermiq is the sole agent consumer. Tool ids change; Hermiq resolves tools from the registry at turn time, so no Hermiq change is required.
- **AVG / persoonsgegevens:** Dossiq handles citizen data. `case.initiatorSourceId` may carry a BSN and `case.initiatorDisplayName` a citizen name; `complaint.klager` is an embedded citizen record. The dialect has **no server-side field projection**, so mitigation is curation, filter-allowlisting and verb restriction only. Recorded in `design.md` § AVG.
- **Out of scope:** agent-writable case transitions, curated aggregation tools (KPI / doorlooptijd), and the `ori_register.json` raadsinformatie schemas.
