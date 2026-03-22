## Why

B&W besluitvorming (Board & Aldermen decision-making) is the #6 most-requested Nice-to-have feature across 20+ tenders (29% of all analyzed), scoring 3-8% of total tender points. The ambtelijk parafering workflow -- where civil servants prepare, review, and approve proposals before they reach the College van B&W -- is a core process in Dutch municipal case management. Without it, municipalities must use external tooling or manual processes for decision preparation, creating data silos and compliance gaps.

The workflow-engine-enhancement (PR #93) now provides the foundation: status transitions with guards, automatic actions, and role-based routing. This change builds the besluitvorming domain logic on top of that engine.

## What Changes

- Add 3 new OpenRegister schemas: `voorstel` (proposal), `parafeerroute` (endorsement route), `parafeeractie` (endorsement action)
- Implement configurable parafeerroutes per case type with sequential step execution
- Build parafering action flow: paraferen (approve), terugsturen (return with comments), adviseren (non-binding opinion)
- Create voorstel detail view with document preview, progress timeline, and action buttons
- Add parafering dashboard with secretariaat overview and personal inbox
- Implement B&W voorstellen panel on case detail view
- Implement besluit (decision) registration linked back to case via existing BrcController/decision schema
- Add audit trail for all parafering actions (Archiefwet compliance)
- Integrate with Nextcloud notifications for task routing

## Capabilities

### New Capabilities
- `voorstel-management`: Voorstel (proposal) CRUD from case context -- creation, document attachment, status lifecycle (concept -> in_parafering -> geaccordeerd -> besloten -> gearchiveerd)
- `parafeerroute-engine`: Configurable endorsement routes with sequential step execution, actor assignment (user/group/role), step type enforcement (advies/parafering/accordering), and admin route configuration
- `parafering-actions`: Parafering action flow -- paraferen, terugsturen with mandatory comment, adviseren with non-binding opinion, delegation (namens) with mandate tracking
- `parafering-dashboard`: Secretariaat overview of all active voorstellen with progress tracking, personal parafering inbox, overdue alerts, and reminder sending
- `parafering-audit-trail`: Immutable audit trail of all parafering actions, route modifications, and delegation events for Archiefwet compliance

### Modified Capabilities
- `roles-decisions`: Besluit registration flow is activated -- the existing decision schema and BrcController are now consumed by the voorstel workflow when a college decision is recorded back
- `case-management`: Case detail view gets a "B&W Voorstellen" panel showing linked voorstellen with status and current step

## Impact

- **Schemas**: 3 new schemas added to `lib/Settings/procest_register.json` (voorstel, parafeerroute, parafeeractie)
- **Frontend**: New Vue components for voorstel detail, parafering actions, parafeerroute admin, dashboard views
- **Backend**: NotificatieService extended for parafering notifications; repair step updated for new schemas
- **Dependencies**: Requires workflow-engine-enhancement (PR #93) for status transitions and guard evaluation
- **Nextcloud integration**: Notifications API (IManager) for parafering task routing, Files API for voorstel documents
- **Feature tier**: V1 (sequential parafering, audit trail, dashboard). V2 deferred: parallel parafering, mobile parafering, iBabs/NotuBiz RIS connector
