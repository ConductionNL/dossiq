## Why

Dutch municipalities are legally required to handle bezwaar (objection) and beroep (appeal) procedures under the Algemene wet bestuursrecht (Awb) chapters 6 and 7. When a citizen objects to an administrative decision (besluit), the municipality must follow a structured process with strict legal deadlines (6-week acknowledgment, 6-12 week resolution), mandatory hearing rights, advisory committee involvement, and formal decision recording. Currently, Procest has no dedicated support for this AWB-mandated process. Market intelligence shows bezwaar/beroep handling appears in 534+ tenders and 2,942+ requirements across Dutch government procurement. With the workflow engine (PR #93) now providing configurable process steps, transitions, guards, and automatic actions, Procest can model the bezwaar/beroep lifecycle as a specialized workflow template with pre-seeded case types.

## What Changes

- Add two new pre-seeded case types: "Bezwaar" (objection) and "Beroep" (appeal) with AWB-compliant status types, deadlines, and role types
- Add a pre-seeded workflow template for each case type with the legally mandated process steps, transitions, and guards
- Add new schemas for bezwaar-specific entities: `objection` (bezwaarschrift), `hearingSession` (hoorzitting), `advisoryReport` (advies bezwaarschriftencommissie), and `appealDecision` (beslissing op bezwaar)
- Add dedicated Vue components for the bezwaar/beroep case detail view: objection intake form, hearing scheduling panel, advisory committee report, and appeal decision form
- Add automatic deadline calculation based on AWB rules (6 weeks default, 12 weeks with extension, suspension support)
- Add automatic actions for deadline warnings, hearing invitations, and decision notifications
- Link bezwaar cases to the original besluit (decision) they object to, creating a parent-child case relationship

## Capabilities

### New Capabilities
- `bezwaar-lifecycle`: AWB-compliant objection lifecycle with pre-seeded case type, status types (ontvangen, ontvankelijkheidstoets, hoorzitting-gepland, hoorzitting-afgerond, advies-uitgebracht, beslissing-op-bezwaar, afgehandeld), deadline calculation, and suspension/extension support
- `bezwaar-hearing`: Hearing (hoorzitting) management for bezwaar procedures including scheduling, invitation, minutes recording, and the right to be heard (hoorplicht) enforcement
- `bezwaar-advisory-committee`: Advisory committee (bezwaarschriftencommissie) workflow with report generation, advice types (gegrond/ongegrond/niet-ontvankelijk/deels gegrond), and integration into the decision process
- `bezwaar-decision`: Decision on objection (beslissing op bezwaar) with formal decision recording, legal grounds, disposition types, and link to the original contested decision
- `beroep-escalation`: Escalation from bezwaar to beroep (appeal to administrative court) with case transfer, court filing tracking, and verdaging (adjournment) support

### Modified Capabilities
- `workflow-definition-model`: Add pre-seeded bezwaar and beroep workflow templates with AWB-mandated steps and transitions
- `case-types`: Add pre-seeded Bezwaar and Beroep case types with AWB-specific status types, role types, and deadline configurations

## Impact

- **Schema**: 4 new schemas in `procest_register.json` (objection, hearingSession, advisoryReport, appealDecision)
- **Register seed data**: Pre-seeded case types, status types, role types, and workflow templates added to repair step
- **Frontend**: 6-8 new Vue components for bezwaar/beroep-specific UI (intake form, hearing panel, advisory report, decision form, timeline view, escalation panel)
- **Store**: New `bezwaar.js` Pinia store module for objection/hearing/advisory CRUD operations
- **Workflow engine dependency**: Requires workflow-engine-enhancement (PR #93) for workflow templates, guards, transitions, and automatic actions
- **Existing decisions**: Links to the existing `decision` schema and `roles-decisions` spec for the original besluit being contested
- **Pipelinq**: Bezwaar intake can originate from Pipelinq via request-to-case conversion when a citizen files an objection through the CRM channel
- **ZGW mapping**: Bezwaar maps to ZGW Zaken API with `zaaktype` "Bezwaar" and `resultaattype` for disposition; beslissing op bezwaar maps to ZGW Besluiten API
