# Proposal: Parafering Actions

## Summary

Implement the actor-facing parafering action UI and e-signature workflow for the Procest B&W parafering system. Actors (adviseurs, parafeerders, accorderende partijen) take structured actions on their assigned step — adviseren (with advice text), paraferen (with optional comment), accorderen, or terugsturen (with mandatory reason). Delegate/mandaat actions are supported for actors acting on behalf of a principal. A voorstel action history timeline displays all parafeeracties in chronological order. A digital PDF signature annotation is applied to the voorstel document when a step of type `accordering` is completed.

## Problem

The `parafeerroute-engine` change implemented the routing engine, admin configuration UI, and step activation with notifications and tasks. However, actors currently have no UI to act on their assigned parafering step: there is no action dialog for parafering, advising, or returning a voorstel, no mandate/delegate flow, no action history view, and no digital signing capability. The `parafeeractie` entity is defined in ADR-000 but no service, controller, or UI records or retrieves these actions.

Without this change:
- Actors receive notifications and tasks but cannot record their parafering decision
- The routing engine cannot advance (step advancement requires a recorded `parafeeractie`)
- Mandate/delegate workflows are unsupported despite the `onBehalfOf` and `mandate` fields in the schema
- Voorstel documents carry no verifiable digital approval trace

## Affected Projects

- [ ] Project: `procest` — Add `ParafeerActieService`, `ParafeerActieController`, actor action dialog components, action history timeline, and PDF signature annotation for accordering steps

## Scope

### In Scope (V1)

- **Actor Action Taking** (REQ-PAA-001): Actor-facing action dialog for `advies`, `parafering`, and `accordering` steps. Step-type-specific fields: advice textarea for `advies` steps, optional comment for `parafering`, confirmation only for `accordering`. Accessible from the Nextcloud task or voorstel detail view. Only the current step actor (or valid delegate) may submit.
- **Return for Revision** (REQ-PAA-002): Any actor at the current step can return the voorstel to the steller with a mandatory written reason. Voorstel status transitions to `teruggestuurd`. The steller receives a Nextcloud notification with the return reason.
- **Delegate / Mandaat Actions** (REQ-PAA-003): Actors can record an action on behalf of a principal. The `onBehalfOf` and `mandate` fields in `parafeeractie` are populated. The UI exposes a "Namens" selector when the logged-in actor has a valid mandate configured in settings.
- **Action History Timeline** (REQ-PAA-004): Read-only timeline embedded in the voorstel detail view listing all `parafeeracties` in chronological order — actor, step number, action type, date/time, and comment/advice text.
- **Digital Signature on Voorstel Document** (REQ-PAA-005): When an `accordering` step is completed, the backend applies a PDF signature annotation to the linked voorstel document (Nextcloud file) recording actor UID, timestamp, step number, and step type. No external e-signature service — uses Nextcloud file system.

### Out of Scope

- External e-signature services (DocuSign, SignRequest, PKIoverheid certificates) — V2
- Parallel step actor management (multiple actors per step) — covered by parafeerroute-engine V2
- Wet digitale overheid / DigiD identity verification for signing — V2
- Bestuurlijk signing (formal College B&W besluit signing) — separate change
- Mobile push notifications for parafering action confirmations — V2

## Approach

1. **Service**: `ParafeerActieService.php` — records parafeeracties via `ObjectService::saveObject` (3-arg API), triggers step advancement via `ParafeerRouteService::completeStep`, handles terugsturen status transitions, and applies PDF signature annotation for accordering steps
2. **Controller**: `ParafeerActieController.php` — `POST /api/parafeer-actie` (record action) and `GET /api/parafeer-actie?voorstel={id}` (retrieve timeline). Per-object authorization: only the current step actor or valid delegate may record an action; otherwise throws `OCSForbiddenException`
3. **Frontend action dialog**: `ParafeerActieDialog.vue` — step-type-aware conditional UI: advice textarea for `advies`, comment textarea for `parafering`/`accordering`, return reason textarea for `terugsturen`; "Namens" delegate selector when mandates exist
4. **Timeline**: `ParafeerActieTimeline.vue` — embedded in `VoorstelDetail.vue`, loads all parafeeracties for the voorstel via `GET /api/parafeer-actie?voorstel=:id`, renders as chronological event list using `CnTimelineStages`
5. **Seed data**: 5 realistic Dutch parafeeractie seed objects added to `procest_register.json`

## Cross-Project Dependencies

- **parafeerroute-engine** (required dependency): `ParafeerRouteService::completeStep` and `::activateStep` are called to advance the route after each action
- **OpenRegister**: `parafeeractie` and `voorstel` object storage via `ObjectService`; audit trail automatic on every save
- **NotificatieService** (platform): Teruggestuurd notification to steller; geaccordeerd notification on final accordering step
- **FileService** (platform): Nextcloud file access for PDF signature annotation on voorstel document
