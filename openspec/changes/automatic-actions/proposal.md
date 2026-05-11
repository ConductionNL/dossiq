# Proposal: automatic-actions

## Summary

Add a declarative **automatic-action registry** so administrators can attach side-effects (send email, create document, notify role, call webhook, merge template, schedule reminder) to status transitions without writing PHP. New handlers plug into the `ActionHandlerInterface` registry shipped by `status-transition-engine` — this change is purely additive.

## Why

`status-transition-engine` ships dispatch plumbing and six built-in handlers, but the catalogue is hard-coded and there is no end-user way to author, parameterise, reuse, or audit actions. Every new side-effect today requires a code change, a release, and a redeploy. Multi-tenant municipalities need a tenant-scoped action library (templates per zaaktype, webhooks per ketenpartner) and a dry-run mode to safely preview an action before publishing it.

## What Changes

- Adds `automaticAction` schema (slug, type, tenantId, title, config, isPublished) to `procest_register.json`.
- Introduces `ActionRegistry` service for per-tenant slug-based lookup.
- Adds six built-in action handlers: `sendEmail` (existing — extended), `createDocument` (new), `notifyRole` (new), `callWebhook` (existing — extended for slug indirection), `mergeTemplate` (new), `scheduleReminder` (new).
- Extends `SideEffectDispatcher` in `status-transition-engine` to resolve `{ref: <slug>}` references.
- Adds admin UI (Vue) to attach, reorder, and dry-run actions per transition.
- Adds `POST /api/automatic-action/{slug}/dry-run` endpoint for safe preview.

## Affected Projects

- [ ] Project: `procest` — Adds `automaticAction` schema, `ActionRegistry`, additional built-in handlers, admin UI to attach actions to transitions, dry-run preview, tenant-scoped catalogue.

## Scope

### In Scope (V1)

- Declarative `automaticAction` JSON schema (REQ-AA-1).
- Per-tenant `ActionRegistry` keyed by `type` + `slug` (REQ-AA-2, REQ-AA-8).
- Built-in handlers `sendEmail`, `createDocument`, `notifyRole`, `callWebhook`, `mergeTemplate`, `scheduleReminder` (REQ-AA-3).
- Admin UI to attach and reorder actions on a transition (REQ-AA-4).
- Engine dispatch hook resolves actions through the registry (REQ-AA-5).
- Dry-run mode renders the projected effect without mutating live state (REQ-AA-6).
- Per-execution result on `statusRecord.dispatchedActions` (REQ-AA-7).

### Out of Scope

- Conditional action gating (guards live in `status-transition-engine`).
- Visual workflow editor canvas (separate spec).
- Time-based triggers beyond `scheduleReminder` — V2.

## Approach

1. Add `automaticAction` schema to `procest_register.json`.
2. Create `ActionRegistry` service that loads tenant-scoped actions by slug.
3. Add six `ActionHandlerInterface` implementations alongside the engine's existing handlers, registered via the `procest.transition_side_effect_handler` DI tag.
4. Add Vue admin UI to attach and reorder actions on transitions, with a dry-run preview pane.
5. Extend `SideEffectDispatcher::dispatch` to resolve action configs from the registry rather than inline JSON.

## Cross-Project Dependencies

- **`status-transition-engine`** (procest): Provides `ActionHandlerInterface`, `SideEffectDispatcher`, `statusRecord.dispatchedActions` surface.
- **`workflow-definition-model`** (procest): Provides the `transitions[].automaticActions[]` array shape.
- **OpenRegister**: Hosts the `automaticAction` schema and tenant-scoped storage.

## Constraints

- MUST NOT introduce a new dispatch path; reuse `SideEffectDispatcher`.
- Actions MUST be tenant-scoped — no cross-tenant leakage.
- Dry-run MUST NOT mutate the case, send mail, write documents, or hit webhook URLs.
