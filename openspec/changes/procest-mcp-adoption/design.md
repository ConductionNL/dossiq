## Context

Procest today ships `lib/Mcp/ProcestToolProvider.php` — a hand-written `IMcpToolProvider` with 2 read-only tools (`procest.listProcesses`, `procest.getProcessDetails`), registered via the AppHost `Bootstrap` `mcpProvider` key in `lib/AppInfo/Application.php`. ADR-063 (hydra #102) supersedes that model: OpenRegister derives `{appId}.{schema}.{verb}` tools from a per-schema `x-openregister-mcp` block, and genuine non-CRUD behaviour is exposed with `#[McpTool]` on a service method. Apps hand-write no MCP tool code.

Two facts from the OpenRegister contract (`origin/development`) shape every decision below:

1. **Hand-written beats derived.** `SchemaDerivedToolProvider` is appended *after* the app's hand-written provider and self-suppresses colliding ids (REQ-DERIVED-003). A surviving `procest.case.search`-equivalent hand-written tool would permanently shadow the derived one — the dialect would be inert. Surgery and dialect must land in the same change.
2. **The dialect has no server-side field projection.** `SchemaDerivedToolProvider::search()` returns whole serialized objects (long strings truncated); `get()` returns the object in full. `fields` is a *caller-supplied* argument, not a schema-declared allowlist. So the only levers Procest has over what an agent can see are: **which schemas are enabled**, **which verbs**, and **which search filters**. This is the whole reason the curation below is conservative.

Procest owns **155 schema slugs** — 83 in `procest_register.json`, 6 in `ori_register.json`, 66 across 18 `register.d/` fragments. Blanket exposure would emit ~775 tools. Specter's research measures ~9.5% LLM tool-selection accuracy degradation and 30k+ tokens of context burn from exactly this. Curation *is* the change.

## Goals / Non-Goals

**Goals:**
- Declare `x-openregister-mcp` on a curated 13-schema set (22 derived tools), read-only.
- Delete the hand-written provider so the derived surface is not shadowed.
- Make the exclusion of the other 142 schemas explicit and reviewable.
- Be honest about what an agent can see (AVG) and what it must never do (Archiefwet, mandate, state machine).

**Non-Goals:**
- Any agent-writable verb. Zero `create`/`update`/`delete` in this change (§ Write verbs refused).
- Curated `#[McpTool]` aggregation tools (KPI, doorlooptijd, termijn reporting) — a follow-up, see Open Questions.
- Exposing `ori_register.json` (raadsinformatie) or any tenant/SaaS-control schema.
- Restoring the provider's assignee/role ACL in any form (§ Access model).

## Decisions

### D1 — Curated schema set: 13 of 155

The dialect block goes in each schema's `configuration` object (`configuration["x-openregister-mcp"]`) — the location `SchemaDerivedToolProvider::mcpAnnotation()` reads, matching the pipelinq exemplar. Every filter listed is a real property of that schema (cross-checked against the JSON; OpenRegister's `McpAnnotationValidator::validateFilters()` rejects unknown ones at import).

| # | Schema (file) | Verbs | `search` filters (all real props) | Why an agent is genuinely asked this |
|---|---|---|---|---|
| 1 | `case` (`procest_register.json`) | search, get | `status`, `caseType`, `assignee`, `priority`, `identifier`, `isFinalStatus` | The zaak. Every question in this domain ("what's the status of case X", "which cases are still open", "what's on my desk") resolves to it. `assignee` + `isFinalStatus` replace the deleted `listProcesses`. |
| 2 | `task` (`procest_register.json`) | search, get | `status`, `isTerminalStatus`, `case`, `assignee`, `dueDate`, `priority` | The taak — "what do I still have to do on this case", "what's overdue". Carries no citizen data (assignee is an NC user id). |
| 3 | `caseType` (`procest_register.json`) | search, get | `identifier`, `catalogus`, `isDraft` | The zaaktype blueprint: statutory `processingDeadline`, confidentiality default, initial status. Public administrative metadata; answers "how long may this take". |
| 4 | `statusType` (`procest_register.json`) | search, get | `caseType`, `isFinal` | `case.status` is a UUID ref; without `statusType.get` the agent cannot *name* a case's status. `search` by `caseType` answers "what are the phases of this process". |
| 5 | `statusRecord` (`procest_register.json`) | search | `case`, `statusType` | The transition history. This is exactly the history join the deleted `getProcessDetails` hand-rolled; OpenRegister sorts and pages it for free. |
| 6 | `decision` (`procest_register.json`) | search, get | `case`, `decisionType`, `decisionDate` | The besluit — the legally operative outcome of a case. Reference fields only, no embedded citizen data. |
| 7 | `result` (`procest_register.json`) | get | — | `case.result` is a UUID ref; `get` lets the agent resolve "what was the outcome". No `search`: a free-text scan of outcomes across all cases has no honest use. |
| 8 | `resultType` (`procest_register.json`) | search, get | `caseType`, `archivalAction` | Carries `archivalPeriod` / `archivalAction` / `selectionListClass` — the Archiefwet retention rules. Answers "how long do we keep this case, and is it destroyed or transferred". Read-only reference data. |
| 9 | `document` (`procest_register.json`) | search, get | `documentType`, `status`, `confidentiality` | The informatieobject — "which documents belong to this case, and are they definitief". Risk-noted below (`content`). |
| 10 | `caseDocument` (`procest_register.json`) | search | `case`, `document` | `document` has no `case` property — this link record is the *only* path from a case to its documents. Search-only; it is a pure join row. |
| 11 | `bezwaar` (`procest_register.json`) | search, get | `case`, `status`, `objection` | The AWB objection lifecycle with its statutory clocks (`decisionDeadline`, `verdaagdOp`, `opschorting`). Top-frequency question in this domain. References only — the citizen sits behind `objection`/`case`. |
| 12 | `termijnInstance` (`register.d/60-termijnbewaking.json`) | search, get | `zaak`, `status`, `termijnDefinitie` | Statutory deadline instances — "which cases are about to blow their termijn". No personal data. |
| 13 | `complaint` (`procest_register.json`) | **get only** | — | The klacht (Awb ch. 9). `get` only, deliberately: `complaint.klager` is an **embedded citizen record** (naam + contact). No `search` ⇒ no fishing for complainants (§ AVG). |

**22 derived tools total** (`search`+`get` per row above), against 775 for the naive surface.

### D2 — Exclusions: 142 schemas OFF, by category

Default is OFF; nothing below needs a decision *to* exclude, but the reviewer needs the reasoning, so it is grouped rather than enumerated.

| Category | Count (approx.) | Examples | Why OFF |
|---|---|---|---|
| **Persoonsgegevens / bijzondere categorieën** | ~20 | `brpPerson` (`burgerservicenummer`), `wmoZaak`/`jeugdwetZaak`/`participatiewetZaak`/`indicatiestelling`/`gezinsplan`/`mdoOverleg`/`toestemming`/`avgIncident`/`avgClassificatie`/`sociaalDomeinAuditLog` (sociaal domein — BSN + health/family data, AVG art. 9), `contactmoment` (`bellerIdentificatie`, `geidentificeerdeBurgerId`, `transcriptie`), `customerContact`, `kvkCompany`, `portaalBericht`/`portaalVerzoek` | Hard OFF. With no field projection, one search hit hands the model a BSN or a care record. There is no verb restriction that makes `brpPerson` or a jeugdwet dossier agent-safe. The right vehicle, if ever needed, is a curated `#[McpTool]` that returns a redacted projection — not the dialect. |
| **Multi-tenant / SaaS control plane** | 10 | `tenant`, `tenantUser`, `tenantQuota`, `tenantBillingEvent`, `tenantMandate`, `tenantConfiguration`, `tenantOnboardingTask` | Operator-plane data, not case data. Nobody asks an assistant about them, and cross-tenant reads are the worst possible blast radius. |
| **Authorisation / mandate / delegation** | ~12 | `mandaat`, `mandateringsBesluit`, `mandaatEscalatie`, `mandaatGebruik`, `organisatieRol`, `medewerkerRolToewijzing`, `substitution`, `parafeerroute`, `parafeeractie`, `role`, `roleType` | These *are* the access-control model. Exposing who may sign what to a model is a reconnaissance surface with no user-facing question behind it. (`role` also links a natural person to a case.) |
| **Audit / log / integrity records** | ~8 | `aiAuditEntry`, `paraferingAuditEntry`, `sociaalDomeinAuditLog`, `stateMachineLog`, `syncQueue`, `conflictRecord`, `dispatch` | Machine bookkeeping. An agent summarising an audit log is an integrity hazard, not a feature. |
| **Config / template / definition data** | ~25 | `workflowTemplate`, `emailTemplate`, `automaticAction`, `propertyDefinition`, `caseProperty`, `termijnDefinitie`, `milestoneDefinition`, `lhsMatrix`/`lhsMatrixCell`, `stufEndpoint`/`zaaksysteemMapping`, `mapLayer`/`wmsLayer`, `kanaal`, `catalogus`, `documentType`/`decisionType`/`informatieobjecttype`, `usageRights` | Admin configuration. Read by admins in the UI, never the subject of a natural-language question. Every one of these would spend context budget for nothing. (`caseType`, `statusType`, `resultType` are the three that *are* asked about — they are ON.) |
| **Domain verticals not in the core zaak flow** | ~40 | subsidie (9: `subsidieAanvraag`…`terugvordering`), VTH/inspectie (~10: `inspectionChecklistRun`, `fieldInspection`, `handhavingsactie`, …), supplier/contract (8: `supplierContract`, `supplierInvoice`, …), advies (`adviceRequest`, `adviesAanvraag`, `advisoryReport`, `bacAdviceRequest`, `advisoryBody`, `bezwaaradviescommissie`), KCC werkplek (`belplan`, `doorverbinding`, `klantSentiment`, `specialistBeschikbaarheid`, `kccQuickAction`, `routingRule`, `kccAgent`, `callbackRequest`), DSO (`samenwerkverzoek`), `abonnement`, `location`, `partnerOrganization`, `voorstel`, `consultation`, `hearing`/`hearingSession`, `beroep`, `objection`, `appealDecision`, `bezwaarDecision`, `complaintCategory`/`complaintDisposition`, `casetransfer`, `caseShare`, `caseObject`, `caseRelation`-likes, `deelzaak`-likes, `checklistItem`, `milestoneRecord`, `documentLink`, `besluitinformatieobject`/`zaakinformatieobject`/`informatieobject`, `dwangsomBerekening`/`dwangsomUitbetaling`/`ingebrekestelling`, `termijnGebeurtenis`, `beschikking`, `bezwaarTrigger`, `mandaatRegeling`, `zaaktypeInformatieobjecttype` | Real features, but each is a *specialist's* workspace reached from a case. An agent that has `case`, `task`, `decision`, `document`, `bezwaar` and `termijnInstance` can already answer the questions people actually ask. Adding a vertical costs 2 tools of context budget each and buys a question nobody asks. **Any of these can be promoted later on evidence of demand** — that is the point of a default-OFF dialect. Note `informatieobject` (ZGW/DRC mirror of `document`) stays OFF specifically to avoid two near-duplicate document surfaces confusing tool selection. |
| **Raadsinformatie (`ori_register.json`)** | 6 | `vergadering`, `agendapunt`, `raadslid`, `fractie`, `stemming`, `raadsdocument` | A separate register and a separate product surface; not case management. |

The principle: **an entity earns a tool by being the subject of a question, not by existing.**

### D3 — Provider surgery: tool-by-tool

| Hand-written tool | Classification | Action | Replacement |
|---|---|---|---|
| `procest.listProcesses(limit?, status?)` | **Derivable CRUD.** Body = `searchObjectsAsArrays(case, filters, _limit)` + a cap. Zero business logic. | **DELETE** | `procest.case.search` — same `status` filter plus 5 more, with real pagination (`page`/`pageSize`/`total`/`hasMore`) instead of a hard 20-item truncation. |
| `procest.getProcessDetails(id\|uuid)` | **Derivable CRUD (composite).** Body = `find(case)` + `searchObjectsAsArrays(statusRecord, case=uuid)` + a `usort` on `createdAt`. The join is a second query, not domain logic. | **DELETE** | `procest.case.get` + `procest.statusRecord.search(filters:{case})`. Two derived calls; OpenRegister sorts and pages. |

Both tools are derivable ⇒ **the provider retains zero tools ⇒ `lib/Mcp/ProcestToolProvider.php` is DELETED** (ADR-063: do not leave an empty seam). Consequently:

- No `#[McpTool]` method is added to any service in this change — nothing survived to move.
- **No `IMcpScannableServices` implementation is added.** The opt-in exists to tell OpenRegister *which services to scan*; with no `#[McpTool]` anywhere in Procest, a `ProcestScannableServices` returning `[]` would be a dead class. It gets added by the first change that introduces a curated tool.
- Also removed: the `'mcpProvider' => ProcestToolProvider::class` key + `use` in `lib/AppInfo/Application.php`, `tests/Unit/Mcp/ProcestToolProviderTest.php`, the `tests/Stubs/Mcp/IMcpToolProvider.php` stub with its `tests/bootstrap.php` require and its `psalm.xml` `referencedClass` suppression.

### D4 — Access model: the provider's ACL does not come back

`ProcestToolProvider::canReadCase()` allowed a read when the caller was a procest/NC admin, the case's `assignee`, or held a `role` record on the case. That ACL **exists nowhere else in Procest**: the Vue frontend reads cases straight from `/apps/openregister/api/objects` via `useObjectStore` (ADR-022 thin client), so the UI's effective access model already *is* OpenRegister RBAC. The provider's ACL was an MCP-only refinement.

Deleting it means OpenRegister RBAC becomes the single gate for the MCP surface — which is precisely ADR-063's contract (`SchemaDerivedToolProvider`: *"RBAC/IDOR unchanged from the REST path"*, invoked in the caller's ambient session, no impersonation). **Net effect: the MCP surface stops being stricter than the app's own UI.** That is a widening of the MCP surface relative to today and is called out as such (proposal marks it BREAKING).

- *Alternative considered:* keep a hand-written `procest.listMyCases` carrying the ACL. **Rejected** — it re-introduces a hand-written CRUD tool, which is exactly what shadows the derived surface (Rule 1), and it hides an ACL in the agent path that the human path does not have.
- *Mitigation:* the "cases I work on" question is served losslessly by `procest.case.search(filters:{assignee: <me>})`, which is why `assignee` is a declared filter.
- *Precondition:* Procest's register/schema RBAC must actually be configured before the dialect ships. A verification task covers it: a non-privileged user must not be able to `procest.case.search` the whole register.

### D5 — AVG / persoonsgegevens

Procest processes citizen data. With no server-side projection (§ Context, fact 2), what an enabled schema returns is *everything it stores*.

- **`case` — ON (search+get), with residual risk accepted and recorded.** `case.initiatorSourceId` is documented as *"BSN (person), KvK-nummer (company), or contact URI"* and `case.initiatorDisplayName` is a citizen's name. Both are returned by `case.get` and by `case.search` hits. Leaving `case` OFF would make the entire change pointless, so it is ON with two hard mitigations: **(a) no identifying property is a declared search filter** — `initiatorSourceId`, `initiatorDisplayName`, `initiatorType` and `requester` are *not* in the filter list, so an agent can never look a case up **by BSN** or enumerate cases by citizen; **(b)** every call runs in the caller's session under OpenRegister RBAC and is written to the immutable hash-chained audit trail. Residual risk: a caseworker who may already read a case can have the model recite its BSN. That is the same exposure the UI gives them; it is *not* a new data flow, but it does put a BSN into an LLM context window. Recorded, not hidden — see Open Questions Q1 (dialect-level field denylist in OpenRegister).
- **`complaint` — `get` only.** `klager` is an embedded object (`naam`, contact details). `get` requires an id the agent already has from the case context; `search` would let it sweep complainants. This is the "restrict to `get` with an explicit AVG note" posture.
- **All BSN-bearing and sociaal-domein schemas — OFF** (D2 row 1). `brpPerson`, `wmoZaak`, `jeugdwetZaak`, `participatiewetZaak`, `indicatiestelling`, `gezinsplan`, `contactmoment` (call recordings + transcripts + `geidentificeerdeBurgerId`), `customerContact`, portal messages. No verb restriction makes these safe under the current dialect; AVG art. 9 data is not going into a tool result.
- **`document` — ON, with a note.** `document.content` is *"Base64-encoded file content or file reference"*. `search` truncates long strings, but `document.get` can return an inline file body. Accepted because the case-file question is core and the alternative (OFF) blinds the agent to the dossier; agents should pass `fields` to read metadata. Flagged in Open Questions Q1 alongside the BSN denylist — it is the same missing OpenRegister capability.

### D6 — Write verbs: all refused

**Zero write verbs are declared on any Procest schema.** This is not caution-by-default; each refusal has a specific reason.

| Refused | Why |
|---|---|
| `case.update` | `case.status` transitions run through `StatusTransitionService` / `StateMachineService`: guard evaluation, `statusRecord` emission, automatic actions, termijn recalculation, notifications. A derived `update` writes the object **straight through `ObjectService`** — it would set `status` with **none** of that. The result is a case whose status is a lie: no transition record, no guards, no clocks. A raw `case.update` is a correctness bug before it is a governance one. |
| `case.delete` | **Archiefwet.** A case is a record under a selectielijst; `resultType.archivalPeriod` / `archivalAction` decide whether it is *vernietigd* or *overgedragen*, and when. Destruction is a formal, authorised, logged act. An agent MUST NOT be able to vernietigen a zaakdossier. Non-negotiable — and the same reasoning bans `delete` on every other Procest schema. |
| `case.create` | A case is a legal dossier with a statutory clock that starts at creation (`processingDeadline`, `termijnInstance`). Creating one has an intake path (`DsoIntakeService`, portaalVerzoek, KCC) that sets initiator, channel, deadlines and initial status. A bare object insert produces a malformed, clock-less case. |
| `decision.create` / `.update` | A besluit is a legally binding act. It requires mandate (`MandaatCheckService`) and a parafering/signing route. An agent issuing a besluit is a mandate breach, full stop. |
| `bezwaar.*`, `complaint.*` writes | AWB status and statutory deadlines (`decisionDeadline`, `afhandelDeadline`, dwangsom exposure). Same argument as `case.update`, with money attached. |
| `task.create` / `.update` | The most defensible candidate, and still refused: tasks are dispatched and completed by `WorkflowEngineService` against a `workflowStepId`; a task created or closed by a raw write bypasses step advancement, and `isTerminalStatus` is a materialised calculation the engine drives. A free-floating agent task would silently desynchronise the workflow. |

The general rule this expresses: **in Procest every lawful write goes through a service that enforces a guard, a mandate, a clock or a retention rule.** The derived write verbs, by construction, do none of that. The correct way to make a Procest write agent-safe is a curated `#[McpTool]` on the *owning service* (e.g. `StatusTransitionService::transition`, which already evaluates guards), with `scope: update`, `destructiveHint: false`, `readOnlyHint: false`. That is a follow-up change, deliberately not smuggled into this one (Q2).

## Risks / Trade-offs

- **[Stale hand-written tool shadows the derived surface]** → Provider deletion and dialect declaration ship in the *same* change; a verification task asserts `procest.listProcesses` is gone from `tools/list` and `procest.case.search` is present.
- **[Union-merge drops the dialect]** → `case` is redefined in `register.d/dso-omgevingsloket.json`. That fragment's `case` carries **only** `properties` (no `configuration`), so a union merge cannot clobber the dialect — but this has bitten before, so a task re-reads the imported schema's `configuration` from OpenRegister after import rather than trusting the file.
- **[Filter rejected at import]** → Every filter in D1 was cross-checked against the schema's `properties`. `McpAnnotationValidator` rejects unknown filters at save time, so a typo fails the import loudly, not silently.
- **[BSN reaches an LLM context]** → Curation + no identifying filters + RBAC + audit trail (D5). Residual and accepted; the real fix is an OpenRegister field denylist (Q1).
- **[MCP reads widen from assignee-scoped to RBAC-scoped]** → Deliberate (D4); brings MCP in line with the UI. Gated on verifying register RBAC before ship.
- **[22 tools is still a lot of context]** → It is 3% of the naive surface. If tool-selection accuracy degrades, `result`, `resultType`, `caseDocument` and `caseType` are the first four to drop — they are resolution helpers, not question subjects.
- **[Agent audit attributes to the session user, not the agent principal]** → Known fleet-wide gap (openregister #369). Not introduced here; noted so the reviewer knows the audit trail names the human, not the model.

## Migration Plan

1. Add `configuration["x-openregister-mcp"]` to the 13 schemas (11 in `procest_register.json`, 1 in `register.d/60-termijnbewaking.json` — `complaint` and the rest are in the main register). `python3 -m json.tool` after every edit.
2. Delete the provider, its registration, its test and its stub in the same commit.
3. Deploy → re-run the register import repair step → assert the dialect survived the import and the derived tools appear.
4. **Rollback:** revert the commit. The dialect is additive JSON; removing it removes the tools. Nothing in Procest's runtime depends on the derived tools existing.

## Open Questions

- **Q1** — OpenRegister dialect has no field denylist/projection. `case.initiatorSourceId` (BSN) and `document.content` (inline base64) are returned to the model. Should a `x-openregister-mcp.redact: [...]` (or schema-level `visible:false` honoured by the derived provider) be added to OpenRegister? Blocks tightening D5 from "accepted residual risk" to "structurally prevented".
- **Q2** — Should `StatusTransitionService::transition()` be exposed as a curated `#[McpTool]` (guard-enforcing, `scope: update`, human-approval-gated in Hermiq)? That is the only architecturally sound way an agent ever advances a Procest case. Needs a product decision, not an engineering one.
- **Q3** — Should the aggregation services (`KpiAggregationService`, `DoorlooptijdService`, `TermijnReportingService`) get read-only curated `#[McpTool]`s? They are genuine non-CRUD and would answer "how are we doing on doorlooptijden" without exposing rows. Would introduce the first `IMcpScannableServices` opt-in.
- **Q4** — `complaint` is `get`-only, so "which complaints are overdue" is unanswerable. Accept, or serve it via a redacted curated tool under Q3?
