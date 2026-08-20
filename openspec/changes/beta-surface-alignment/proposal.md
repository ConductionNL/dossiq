# Beta surface alignment — Procest

## Why

Procest ships a large, real feature set (77 controllers spanning VTH permits/supervision/enforcement,
bezwaar & beroep, WOO disclosure requests, DSO, KCC, dwangsom enforcement payments, a workflow
board, a case map, appointment booking, and a full ZGW registratiecomponenten stack), but the four
public-facing surfaces (info.xml, the conduction.nl product page, the docs site) had drifted out of
sync with each other and, in several places, out of sync with the actual code. This change reconciles
vocabulary across all four surfaces and removes marketing claims that have zero backing code, per the
beta-release-readiness pass applied fleet-wide.

## Canonical feature vocabulary (source: `src/manifest.json` nav + `lib/Controller/` + `lib/BackgroundJob/`)

Verified real, shipped features used consistently across info.xml, the product page, and docs:

- Configurable case types (case-type engine: statuses, deadlines, required documents, properties)
- VTH process templates — omgevingsvergunning (regulier/uitgebreid), toezichtzaak (bouw/milieu),
  handhavingszaak, sloopmelding (`lib/Settings/vth-templates/*.json`)
- Bezwaar & beroep (objection & appeal) — AWB-compliant lifecycle, hearings, advisory-committee
  tracking, court dossier export (`BesluitvormingController`, `SeedBezwaarBeroepData`, etc.)
- WOO disclosure requests (`WOOAssessmentController`, `woo-verzoek.json` template, `WOORedactionService`)
- Workflow board — kanban-style case-by-status view (`src/views/workflow-board/WorkflowBoard.vue`)
- Dwangsom (penalty payment) tracking — ingebrekestelling, accrual, payment callback
  (`DwangsomController`, `DwangsomPaymentCallbackController`, `IngebrekestellingController`)
- Case map — cases plotted by location, configurable map layers (`CasesOnMapView.vue`,
  `LocationPicker.vue`, Leaflet + leaflet-draw + leaflet.markercluster)
- Appointment booking — citizen timeslot scheduling, cancel, no-show (`AppointmentController`,
  `PublicAppointmentController`)
- Status lifecycle, automatic deadlines, tasks & decisions, participant roles, document checklists,
  sub-cases, 8 confidentiality levels (openbaar → zeer geheim), My Work dashboard
- ZGW API alignment — real ZRC/ZTC/BRC/DRC/NRC + ZgwController/ZgwMappingController implementations,
  not just "compatibility in mind"
- 7 real Nextcloud dashboard widgets (`lib/Dashboard/*.php`, `IWidget`): Cases overview, My Tasks,
  Overdue Cases, Deadline Alerts, Task Reminders, Stalled Cases, Start case
- n8n automatic actions (webhook-driven case automation, `docs/n8n-*-workflows.md`, `automatic-actions` feature)
- AI-assisted processing — Ollama-compatible LLM endpoint for document classification, data
  extraction, summarization, routing suggestions, Q&A (`AiController`, `AiService`)

## Reconciliation — per surface

### 1. `appinfo/info.xml` (EN + NL)
- Description previously undersold the app as generic "case management with configurable
  workflows" and omitted VTH/bezwaar-beroep/WOO/dwangsom/map/appointments/kanban entirely, despite
  these being the app's actual scope (77 controllers, 14 background jobs, 19 repair steps).
  Expanded the feature bullet list to include: VTH process templates, bezwaar & beroep, WOO
  requests, workflow board, dwangsom tracking, case map, appointment booking.
- "Standards-aligned — Built with CMMN 1.1 and ZGW API compatibility in mind" overclaimed CMMN
  1.1 conformance (there is no CMMN runtime/import/export anywhere in `lib/`; CMMN vocabulary is
  used only as a design/mapping language in `openspec/specs/*`, and the app's own docs
  (`docs/Features/README.md`) already mark CMMN 1.1 support as "Partial"). Corrected to "Deep ZGW
  API alignment ... workflow templates modelled on CMMN case-management concepts".
- Version (0.2.40), licence (EUPL-1.2), and OpenRegister dependency comment were already correct;
  left unchanged.

### 2. `src/manifest.json`
No changes — already the accurate source of truth; the nav/menu labels (Cases, Objections, Appeals,
Workflow board, Fee calculations, Map, Deadline monitoring, etc.) match the controllers 1:1 and were
used to build the canonical vocabulary above.

### 3. Product page — `conduction-website/src/pages/apps/procest.mdx` (+ `i18n/nl/.../procest.mdx`)
- **Version/status**: `v1.6` / "Stable" → `v0.2.40` / "Beta" (info.xml is the version source of
  truth per the beta-alignment convention; 0.2.x is pre-1.0 and unreleased-as-stable).
- **FeatureItem 1** ("Pre-defined VTH process models"): the named case types — *milieumelding*,
  *brandveiligheid*, *BAG-melding*, *RUD-controle* — do not exist as workflow templates anywhere in
  the codebase (`brandveiligheid`/`milieumelding` appear only as free-text tag examples/sample data
  IDs, not shipped templates; `BAG-melding`/`RUD-controle` have zero matches). Replaced with the
  real shipped templates: omgevingsvergunning, toezichtzaak, handhavingszaak, sloopmelding, plus
  bezwaar & beroep and WOO-verzoek case types.
- **FeatureItem 3** ("Decisions via DocuDesk... signs and archives it per TMLO"): verified
  `lib/Service/Beschikking/` — `SigningAdapterInterface` has only a `MockSigningAdapter`,
  `TemplateEngineAdapterInterface` has only a `MockTemplateEngineAdapter`; there is no live
  DocuDesk API call anywhere (the one "docudesk" hook in `WOORedactionService` explicitly comments
  "Actual API call deferred ... For now we record the intent"). Archival IS real and TMLO-tagged
  (`OpenRegisterArchivalAdapter` resolves OpenRegister's `TmloService`). Rewrote the claim to
  separate real (composition + OpenRegister TMLO archival) from pluggable-but-mocked
  (PDF generation + signing via adapter, DocuDesk optional).
- **FeatureItem 4** ("Citizen portal via ZaakAfhandelApp... track their case at
  jouwgemeente.nl/zaken"): zero references to Procest in `zaakafhandelapp/lib` or `.../src`, and
  vice versa — the two apps share no code, no shared register wiring, no API calls. This is a
  fabricated live-integration claim. Replaced the FeatureItem with a real, verified feature
  (workflow board + case map + appointment booking) and softened the "Pairs well with" PairCard to
  describe ZaakAfhandelApp as an ecosystem sibling app rather than an integrated citizen portal.
- **WidgetShelf** (EN page only): widget titles "Werkvoorraad" / "Deadlines today" / "Recent
  decisions" don't match any of the 7 real `IWidget` classes' `getTitle()` strings, and no widget
  surfaces "recent decisions" at all. Renamed to the real titles (Cases overview, Deadline alerts)
  and replaced "Recent decisions" with "Overdue cases" (a real widget).
- **RotatingCards** card 3: "Werkvoorraad widget" implied one specific widget by that name; there
  is no widget with that title. Reworded to "Case widgets" (plural, generic) matching the actual
  set of 7 dashboard widgets.
- **Showcase** (EN page only):
  - "Windmill and n8n": zero references to Windmill anywhere in the codebase. n8n IS real
    (`docs/n8n-complaint-workflows.md`, `docs/n8n-consultation-workflows.md`, `AiService`,
    `AdvisoryBodyService`, `CaseDefinitionImportService`, the `automatic-actions` manifest feature).
    Dropped Windmill, kept n8n only.
  - "LLMs (Claude, Mistral, Ollama)... Presidio strips PII": zero references to Presidio anywhere
    in `lib/` or `src/` (Presidio only appears in `docs/Features/ai-assisted-processing.md` under
    an explicit "Planned Features" heading, status "under development" — correctly labelled there,
    but the marketing page presented it as shipped). Replaced with the real, shipped AI feature:
    `AiController`/`AiService` call an Ollama-compatible LLM endpoint for document classification,
    extraction, summarization, and routing suggestions (verified: `// Build the request payload for
    Ollama-compatible API` in `AiService.php`).

### 4. Docs — `docs/`
Reviewed `docs/index.md`, `docs/Features/README.md`, `docs/Features/ai-assisted-processing.md`,
`docs/Technical/architecture.md`. These already use honest status labels ("Implemented" / "In
development" / "Partial" / "Planned Features") and were not the source of the drift — no changes
needed here. `docs/features.json` (used to generate the docs feature index) is comprehensive and
already an accurate description of shipped vs. adapter-backed-but-mocked behaviour (e.g.
`beschikking-generatie` already says "PDF rendering, archival, and Berichtenbox delivery are
integrated via adapter interfaces"). Left unchanged.

## Claims verified vs. removed

| Claim | Verdict | Evidence |
|---|---|---|
| Configurable case types / status lifecycle / deadlines / tasks & decisions / roles / document checklists / sub-cases / 8 confidentiality levels / My Work dashboard | **Verified** | `lib/Settings/procest_register.json`, `lib/Controller/*` |
| VTH templates (omgevingsvergunning, toezichtzaak, handhavingszaak, sloopmelding) | **Verified** | `lib/Settings/vth-templates/*.json` |
| VTH templates milieumelding / brandveiligheid / BAG-melding / RUD-controle | **Removed** | zero shipped templates; only free-text tag examples or no match at all |
| Bezwaar & beroep AWB workflow | **Verified** | `docs/features.json` bezwaar-* entries, 6+ Repair steps, `BezwaarTermijnJob` |
| WOO disclosure requests | **Verified** | `WOOAssessmentController`, `woo-verzoek.json`, `WOODeadlineCheckJob` |
| Workflow board (kanban) | **Verified** | `src/views/workflow-board/WorkflowBoard.vue` |
| Dwangsom / payment tracking | **Verified** | `DwangsomController`, `DwangsomPaymentCallbackController` |
| Case map / locations | **Verified** | `CasesOnMapView.vue`, Leaflet deps in `package.json` |
| Appointment booking | **Verified** | `AppointmentController`, `PublicAppointmentController` |
| ZGW API compatibility | **Verified (stronger than claimed)** | full ZRC/ZTC/BRC/DRC/NRC controller stack |
| CMMN 1.1 compliance | **Corrected** | no CMMN runtime in code; own docs mark it "Partial"; reworded to "modelled on CMMN concepts" |
| Decisions "via DocuDesk... signs and archives per TMLO" | **Corrected** | archival is real (OpenRegisterArchivalAdapter + OR TmloService); signing/PDF generation are Mock-only adapters, no live DocuDesk call |
| Citizen portal via ZaakAfhandelApp (live status/document upload) | **Removed** | zero coupling between the two apps' code |
| n8n automation | **Verified** | `docs/n8n-*.md`, manifest `automatic-actions` feature |
| Windmill automation | **Removed** | zero references anywhere |
| Presidio PII redaction pipeline | **Removed** | zero references in `lib/`/`src/`; only appears in docs as an explicitly "Planned" (not shipped) feature |
| AI-assisted document classification/extraction/summarization | **Verified** | `AiController`, `AiService` (Ollama-compatible endpoint) |
| Dashboard widgets on every install | **Verified**, names corrected | 7 real `IWidget` classes; renamed claims to match real `getTitle()` strings |

## Icon status

`img/app.svg` is a correct white-fill, 24×24 viewBox SVG per the app-icon convention. No mismatch
with the product/docs pages (which use their own inline SVG glyphs for the hero icon, not the app
icon directly). No change needed.

## Still misaligned — needs a decision

- The product page's "Pairs well with" section still lists ZaakAfhandelApp/DocuDesk as if a
  deliberate three-app VTH suite exists; today these are three independently-developed apps that
  happen to share ZGW/EUPL/Nextcloud conventions with no code-level integration. Worth a product
  decision: either build the real integration (shared register linking, DocuDesk adapter
  implementation) or keep the page's ecosystem-positioning framing (as reworded here) rather than
  implying a wired-up integration.
- Version numbers are unusually fragmented across the repo: `appinfo/info.xml` = 0.2.40 (informational,
  used as canonical here), `package.json` = 0.1.0 (frontend build tooling version, not
  user-facing). No action taken — flagging in case a future pass wants single-sourcing.
