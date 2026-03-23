# Workflow Engine Enhancement

## Summary

Enhance Procest's workflow engine to support fully configurable zaaktype workflows with zero-code configuration of process steps, status transitions, and task routing. This is the foundational change that enables VTH, Bezwaar/beroep, and Besluitvorming as follow-up workflow configurations rather than separate feature builds.

The engine must allow functional administrators to define zaaktype-specific workflows (process steps, status transitions, checklists, automatic actions) without developer involvement, using a visual drag-and-drop interface.

## Demand Evidence

### Cluster Data (from market intelligence DB)

| Cluster | Requirements | Tenders |
|---------|-------------|---------|
| Zaaktype configuration | 1,022 | 147 |
| No-code / zero coding configuration | 603 | 221 |
| Drag-and-drop interface | 965 | 289 |
| Status transitions | 190 | 101 |
| Process steps | 90 | 47 |
| Process automation / workflow | 72 | 35 |
| **Total** | **2,942** | **~534 unique** |

### Top Tenders

| Tender | Organisation | URL |
|--------|-------------|-----|
| Levering en ondersteuning nieuw VTH systeem op basis van SaaS | Omgevingsdienst Noordzeekanaalgebied | https://www.tenderned.nl/aankondigingen/overzicht/308208 |
| Zaaksysteem | Veiligheidsregio Brabant-Noord | https://www.tenderned.nl/aankondigingen/overzicht/319120 |
| Zaaksysteem | Gemeente Overbetuwe | https://www.tenderned.nl/aankondigingen/overzicht/331221 |
| Zaaksysteem | Gemeente Overbetuwe | https://www.tenderned.nl/aankondigingen/overzicht/316500 |
| Document Management Systeem | Tilburg University | https://www.tenderned.nl/aankondigingen/overzicht/402469 |

### Representative Requirements from Tenders

1. "Zaaktypen kunnen, zelfstandig en zonder tussenkomst van de Opdrachtnemer, op basis van zero coding volledig worden ingericht."
2. "Zaaktypen en webformulieren kunnen, zelfstandig en zonder tussenkomst van de Opdrachtnemer, op basis van zero coding volledig worden ingericht."
3. "Zaaktypen worden zelfstandig en zonder tussenkomst van de leverancier volledig ingericht op basis van zero coding, passend bij de primaire processen van NIWO."
4. "Er wordt minimaal een test-, acceptatie- en productieomgeving beschikbaar gesteld zonder beperkingen op het aantal gebruikers. De zaaktypeconfiguratie kan zonder tussenkomst van de leverancier van de ene naar de andere omgeving gebracht worden."
5. "Indien een zaaktype door bijvoorbeeld een wetswijziging verandert, moeten lopende zaken nog met het voorgaande zaaktype, dat gebaseerd is op de oude wetgeving, worden afgehandeld."
6. "In de ZTC2 van de oplossing is het bij zaaktypen mogelijk om bij elk statustype, voordat de statusovergang kan plaatsvinden, het volgende te definiëren..."
7. "Op basis van zaaktypen en door verschillende beheerders (delegatie) is het mogelijk om configuraties, rollen en rechten te stapelen, overerven, kopiëren..."
8. "Het is mogelijk in de workflow bij een processtap in te stellen dat een geautomatiseerde statusmail wordt verzonden naar de zaakklant."

## Scope

### In Scope

- **Workflow definition model**: Define workflow templates per zaaktype (process steps, transitions, guards, actions)
- **Visual workflow editor**: Drag-and-drop interface for building workflows (status nodes, transition arrows, conditions)
- **Status transition engine**: Configurable pre-conditions (checklists, required fields, role guards) before status changes
- **Process step configuration**: Ordered steps within a status, with assignable tasks and automatic actions
- **Zaaktype versioning**: Running cases keep their workflow version; new cases use the latest version
- **Workflow import/export**: Move workflow definitions between OTAP environments without developer help
- **Automatic actions**: Trigger emails, task creation, deelzaak creation on status transitions
- **Role-based step routing**: Configure which roles can execute which steps/transitions

### Out of Scope (follow-up changes)

- VTH-specific workflow templates and domain logic (see `vth-workflow-configuration`)
- Bezwaar/beroep workflow templates (see `bezwaar-beroep-workflow`)
- Besluitvorming workflow templates (see `besluitvorming-workflow`)
- Signalering/notification widgets (see `signalering-widgets`)
- GIS integration (see `gis-integration`)

### NOTE

VTH, Bezwaar/beroep, and Besluitvorming are follow-up changes that **configure** this engine with domain-specific workflows. They should not require new engine functionality -- only workflow template definitions and potentially small domain-specific extensions (e.g., leges calculation hooks for VTH).

## Dependencies

- **OpenRegister**: Workflow definitions stored as OpenRegister objects with schema validation
- **Procest case-types spec**: Existing zaaktype/status model in `openspec/specs/case-types/`
- **Procest task-management spec**: Task creation/assignment within workflows
- **CMMN 1.1**: Workflow model should align with CMMN concepts (CasePlanModel, stages, tasks, milestones)

## Acceptance Criteria

1. GIVEN a functional administrator, WHEN they open the workflow editor for a zaaktype, THEN they can visually define process steps and status transitions without writing code
2. GIVEN a workflow definition with pre-conditions on a status transition, WHEN a case handler attempts the transition without meeting all conditions, THEN the transition is blocked and the unmet conditions are displayed
3. GIVEN a zaaktype with a configured workflow, WHEN a new case of that type is created, THEN the case follows the defined workflow with the correct initial status and available transitions
4. GIVEN a zaaktype whose workflow is updated, WHEN there are running cases of that type, THEN running cases continue with the previous workflow version while new cases use the updated version
5. GIVEN a workflow definition in a test environment, WHEN an administrator exports and imports it to production, THEN the workflow is transferred without requiring developer intervention
6. GIVEN a workflow step configured with an automatic email action, WHEN the status transition occurs, THEN the configured email is sent to the zaakklant
7. GIVEN a workflow step restricted to a specific role, WHEN a user without that role attempts to execute the step, THEN they are denied and see a clear explanation
8. GIVEN multiple zaaktypen, WHEN an administrator configures workflow inheritance, THEN child zaaktypen inherit parent workflow steps and can override specific steps
