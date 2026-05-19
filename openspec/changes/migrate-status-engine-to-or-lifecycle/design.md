# Design: migrate-status-engine-to-or-lifecycle

## Context

Three procest schemas have lifecycle state machines currently implemented as PHP
constants and service calls. This document identifies each schema, shows the
before/after migration pattern, and records the declarative-vs-imperative decision
for every behaviour per ADR-031.

## Affected Schemas

| Schema | OR register path | Current lifecycle PHP | New lifecycle path |
|---|---|---|---|
| `Voorstel` | procest register | `ParaferingService::STATUS_*` constants + `saveObject()` | `x-openregister-lifecycle` in register |
| `Parafeerroute` | procest register | `ParaferingService` step management + `currentStep` manual update | `x-openregister-lifecycle` for route-level states; step routing stays in PHP |
| `Bezwaar` | procest register | `bezwaar-lifecycle` spec status types enforced by app | `x-openregister-lifecycle` mirroring AWB status sequence |

## Declarative-vs-Imperative Decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| Voorstel lifecycle states (concept → in_parafering → teruggestuurd / geparafeerd / afgewezen) | `x-openregister-lifecycle` | Four states, simple transitions — exact fit |
| Voorstel submission guard (steller filled required fields) | PHP guard class `VoorstelSubmitGuard` via `requires` | Non-trivial precondition; explicitly preserved per ADR-031 §"PHP guards remain a legitimate seam" |
| Parafeerroute route-level states (actief / afgerond / geannuleerd) | `x-openregister-lifecycle` | Simple boolean route completion states |
| Advancing active `parafeerstap` within a route (step orchestration) | PHP `ParaferingService` step-routing methods | Orchestrates related `parafeerstap` objects; not a single-object state machine |
| Bezwaar AWB lifecycle (Ontvangen → Beslissing op bezwaar; Niet-ontvankelijk / Ingetrokken as terminal) | `x-openregister-lifecycle` | Matches AWB chapter 6/7 sequence declared in `bezwaar-lifecycle` spec |
| Bezwaar deadline guard (processingDeadline not expired) | PHP guard class `BezwaarDeadlineGuard` via `requires` | Reads `processingDeadline` + clock; non-trivial |
| Automatic actions on transition (send email, create task) | Schema hooks on `updated` event → n8n | Replace Application.php listeners; engine selection is per-hook |

## Before / After — Voorstel Schema

### Before: ParaferingService PHP constants

```php
// lib/Service/ParaferingService.php
public const STATUS_CONCEPT       = 'concept';
public const STATUS_IN_PARAFERING = 'in_parafering';
public const STATUS_TERUGGESTUURD = 'teruggestuurd';
public const STATUS_GEPARAFEERD   = 'geparafeerd';

// Transition: manual saveObject call
$voorstel['lifecycle'] = self::STATUS_IN_PARAFERING;
$this->objectService->saveObject($voorstel);
```

### After: x-openregister-lifecycle in register

```jsonc
// lib/Settings/procest_register.json — Voorstel schema (abbreviated)
"Voorstel": {
  "type": "object",
  "properties": {
    "lifecycle": {
      "type": "string",
      "enum": ["concept", "in_parafering", "teruggestuurd", "geparafeerd", "afgewezen"],
      "default": "concept"
    }
  },
  "x-openregister-lifecycle": {
    "property": "lifecycle",
    "initial": "concept",
    "transitions": [
      {
        "name": "indienen",
        "from": "concept",
        "to": "in_parafering",
        "requires": "OCA\\Procest\\Lifecycle\\VoorstelSubmitGuard"
      },
      {
        "name": "terugsturen",
        "from": "in_parafering",
        "to": "teruggestuurd"
      },
      {
        "name": "completeren",
        "from": "in_parafering",
        "to": "geparafeerd"
      },
      {
        "name": "afwijzen",
        "from": ["concept", "in_parafering"],
        "to": "afgewezen"
      },
      {
        "name": "heropenen",
        "from": "teruggestuurd",
        "to": "concept"
      }
    ]
  }
}
```

The `ParaferingService` status constants are removed. The voorstel's `lifecycle`
field is set via a standard PATCH request; OR's lifecycle engine validates the
transition and rejects invalid transitions with HTTP 422.

## Bezwaar Schema — AWB Lifecycle Mapping

The bezwaar AWB lifecycle from the `bezwaar-lifecycle` spec maps directly to
`x-openregister-lifecycle` transitions:

```jsonc
"Bezwaar": {
  "x-openregister-lifecycle": {
    "property": "status",
    "initial": "ontvangen",
    "transitions": [
      { "name": "ontvankelijkheidstoets_starten", "from": "ontvangen", "to": "ontvankelijkheidstoets" },
      { "name": "in_behandeling_nemen", "from": "ontvankelijkheidstoets", "to": "in_behandeling" },
      { "name": "hoorzitting_plannen", "from": "in_behandeling", "to": "hoorzitting_gepland" },
      { "name": "hoorzitting_afronden", "from": "hoorzitting_gepland", "to": "hoorzitting_afgerond" },
      { "name": "advies_uitbrengen", "from": "hoorzitting_afgerond", "to": "advies_uitgebracht" },
      { "name": "beslissen", "from": ["hoorzitting_afgerond", "advies_uitgebracht", "in_behandeling"], "to": "beslissing_op_bezwaar" },
      { "name": "afronden", "from": "beslissing_op_bezwaar", "to": "afgehandeld" },
      { "name": "niet_ontvankelijk_verklaren", "from": "ontvankelijkheidstoets", "to": "niet_ontvankelijk" },
      { "name": "intrekken", "from": ["ontvangen", "ontvankelijkheidstoets", "in_behandeling", "hoorzitting_gepland"], "to": "ingetrokken" },
      { "name": "hoorzitting_overslaan", "from": ["ontvankelijkheidstoets", "in_behandeling"], "to": "advies_uitgebracht",
        "requires": "OCA\\Procest\\Lifecycle\\HoorzittingAfzienGuard" }
    ]
  }
}
```

The `hoorzitting_overslaan` transition (skip hearing when right is waived, per AWB art. 7:2)
uses a PHP guard that checks whether the bezwaarmaker has waived the hearing right.

## Public API Preservation

Existing procest REST endpoints that change case/voorstel status are preserved.
They now issue a PATCH request to the OR object endpoint with the new lifecycle
field value. OR's lifecycle engine validates and applies the transition. If the
transition is invalid (not in `transitions` array or guard fails), OR returns
HTTP 422 and the endpoint propagates the error.

Controllers that previously called `ParaferingService::transitionTo()` now call
`ObjectService::saveObject($voorstel)` directly with the updated `lifecycle` value.
OR intercepts the save, validates the lifecycle transition, and either persists or
rejects.

## Schema Hooks for Automatic Actions

Automatic actions previously registered in Application.php as `ObjectUpdatedEvent`
listeners are migrated to schema hooks in `procest_register.json`:

```jsonc
"x-openregister-hooks": [
  {
    "event": "updated",
    "engine": "n8n",
    "workflowId": "procest-parafering-notification",
    "mode": "async",
    "condition": { "lifecycle": "in_parafering" }
  },
  {
    "event": "updated",
    "engine": "n8n",
    "workflowId": "procest-voorstel-completed",
    "mode": "async",
    "condition": { "lifecycle": "geparafeerd" }
  }
]
```

The existing n8n workflows are unchanged; only the trigger mechanism moves from
a PHP listener to a declarative schema hook.

## PHP Classes That Remain

- `lib/Service/ParaferingService.php` — step-routing methods (`activateNextStep()`,
  `getActiveStep()`, `recordStepAction()`) remain in PHP. These orchestrate
  `parafeerstap` objects, not lifecycle states. Status constants are removed.
- `lib/Lifecycle/VoorstelSubmitGuard.php` (NEW) — validates required fields before
  the `indienen` transition. Returns `bool`. Does not call `saveObject()`.
- `lib/Lifecycle/BezwaarDeadlineGuard.php` (NEW) — validates deadline has not
  expired before certain bezwaar transitions. Returns `bool`.
- `lib/Lifecycle/HoorzittingAfzienGuard.php` (NEW) — validates hearing waiver
  flag before the `hoorzitting_overslaan` transition. Returns `bool`.

## Seed Data

This change adds no new schemas. It modifies existing schema definitions in
`lib/Settings/procest_register.json` to add `x-openregister-lifecycle` blocks.
No new register objects are required.

OR's `object-lifecycle` spec defines no seed data requirements for lifecycle
extensions. Schema definitions update via the existing repair step
(`ConfigurationService::importFromApp()`).

## Historical Records

Historical workflow execution records remain in deprecated stores (read-only).
No data migration is required. The `x-openregister-lifecycle` extension applies
only to future transitions from the moment it is deployed.

## Related Specs (Body Unchanged)

- `status-transition-engine` — describes the guard evaluation model; now
  implemented by OR's lifecycle engine + PHP guard classes.
- `workflow-definition-model` — data model for workflow templates; unchanged.
- `workflow-import-export` — template import/export via `workflow-in-import`;
  unchanged. Templates ship as schema extensions from this change forward.
- `visual-workflow-editor` — frontend editor that produces register patches;
  unchanged (frontend tool).
- `vth-workflow-templates` — VTH templates ship as `x-openregister-lifecycle`
  blocks in the register; no separate runtime.
- `parafeerroute-engine` — step routing stays in PHP; route-level lifecycle
  states move to schema extension.
