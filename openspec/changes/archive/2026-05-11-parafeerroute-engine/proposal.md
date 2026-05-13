# Proposal: Parafeerroute Engine

## Summary

Implement the core parafeerroute configuration and execution engine for the Procest B&W parafering workflow. Beheerders can define named approval routes (parafeerroutes), link them to case types and voorstel types, and the system executes these routes sequentially — activating each step with tasks and notifications and advancing to the next step when complete. Authorized managers can override routes on in-flight voorstellen (skip steps, add ad-hoc steps) with mandatory audit trail entries.

## Problem

The `bw-parafering` spec established the data model (`voorstel`, `parafeerroute`, `parafeeractie` in ADR-000) and defined the ambtelijk parafering workflow. However, the underlying engine that registers the `parafeerroute` schema, manages the admin configuration UI, executes sequential routing, and handles runtime overrides is not yet implemented. Without this engine, voorstellen cannot be routed through configurable approval chains: parafering steps are undefined, no tasks are created for actors, and no notifications are dispatched when a step becomes active.

## Affected Projects

- [ ] Project: `procest` — Add `parafeerroute` and `parafeeractie` schema registration, `ParafeerRouteService` (routing engine), `ParafeerRouteController`, admin route configuration UI, and voorstel override capability

## Scope

### In Scope (V1)

- **Parafeerroute Schema Registration** (REQ-PRE-001): `parafeerroute` schema in Procest OpenRegister with `name`, `caseType`, `voorstelType` (enum: `dt_advies`, `collegeadvies`, `raadsvoorstel`), `steps` (array of `parafeerstap` objects with `order`, `type`, `actor`, `actorType`, `mandatory`), `isDefault`, `description`
- **Sequential Step Routing Engine** (REQ-PRE-002): `ParafeerRouteService` activates steps sequentially; captures `routeSnapshot` on voorstel at submission time; creates a Nextcloud task and notification for each step actor; advances `currentStep` on completion; marks voorstel `geaccordeerd` when final step completes
- **Admin Parafeerroute Configuration** (REQ-PRE-003): Admin settings tab "Parafeerroutes" with full CRUD for routes, ordered step editor, caseType + voorstelType linking, default route flag enforcement
- **Override Route on Specific Voorstel** (REQ-PRE-004): Authorized managers can skip optional steps (mandatory reason, audit trail) or add ad-hoc steps (renumbers subsequent steps in `routeSnapshot`, audit trail)

### Out of Scope

- Parallel parafering (multiple actors per step with completion rules) — V2
- Mobile push notifications for parafering — V2
- RIS connector (iBabs/NotuBiz) for bestuurlijke behandeling — separate change
- Vergaderbeheer, agendabeheer, besluitenlijst — separate change

## Approach

1. **Schema**: Add `parafeerroute` and `parafeeractie` to `procest_register.json` with Dutch seed routes covering common voorstel types (collegeadvies, DT-advies, raadsvoorstel)
2. **Engine**: `ParafeerRouteService.php` handles route CRUD plus the step lifecycle: `startParafering` (captures `routeSnapshot`, activates step 1), `completeStep` (records `parafeeractie`, advances), `skipStep` (records skip with reason, appends to `auditTrail`), `addAdhocStep` (updates `routeSnapshot`, renumbers)
3. **Controller**: `ParafeerRouteController.php` exposes REST endpoints for admin CRUD and voorstel-level override operations
4. **Admin UI**: `ParafeerRoutesTab.vue` embedded in admin settings, `ParafeerRouteDialog.vue` for create/edit, `ParafeerStapEditor.vue` for ordered step management
5. **Notifications**: Reuse `NotificatieService` (step activation, skip, geaccordeerd events) and `TasksController` (task per step actor) — no custom notification logic needed

## Cross-Project Dependencies

- **OpenRegister**: `parafeerroute`, `parafeeractie`, and `voorstel` object storage and relation management
- **NotificatieService** (platform): Nextcloud in-app notifications per step activation and override events
- **TasksController** (platform): Auto-create tasks for each step actor, linked to the parent case
- **Admin Settings UI** (existing): Embed new `ParafeerRoutesTab` in the existing admin settings page
