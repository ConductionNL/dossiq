# Design — procest adopts OR abstractions

This design document focuses on the **lifecycle annotation** that absorbs
four overlapping procest specs and ~200 LOC of state-machine PHP. It also
documents the notifications annotation, the bezwaar deadline calculation
annotation, and the boundary that keeps ZGW protocol code app-local.

Audit references:
`.claude/audit-2026-05-03/01-code-cleanup.md`,
`02-spec-rewrite.md` (lines 60–73, 96–105, 113–132),
`04-hardcoded.md` (lines 82–99, 144–172).

## Decisions

### D1 — One lifecycle annotation, not four specs

Procest currently describes its case + parafering state machine across
four specs (`case-management`, `parafering-actions`,
`parafering-audit-trail`, `parafeerroute-engine`). The audit identified
this as the heaviest spec rewrite debt of any audited app.

**Decision:** Consolidate into a single `x-openregister-lifecycle`
annotation on the case schema. The annotation expresses states,
transitions, guards, role-based authorization, and audit-recording
requirements as data. `case-management/spec.md` becomes the canonical
home; the three sibling specs are retired with closing notes.

**Why:** The four specs all describe one state machine from different
angles. OR provides this natively. Maintaining four specs encourages
implementation drift; consolidating to data lets OR own the engine and
procest own only the configuration.

### D2 — Notifications as annotations, not a service

The three transition notifications in
`ParaferingNotificationService.php:64,109,153` are textbook
`x-openregister-notifications` candidates. The PHP-built `setSubject()`
in `CaseEmailService.php:92` adds another instance of the same
anti-pattern.

**Decision:** Express all three transition notifications as
`x-openregister-notifications` annotations. Delete
`ParaferingNotificationService.php` (in the follow-up code change). Move
`CaseEmailService` notification copy into the annotation.

**Why:** Notifications are configuration, not code. Centralising them in
the schema makes i18n trivial (one source of truth per ADR-025), makes
the rules visible to schema readers, and aligns with pipelinq /
opencatalogi patterns.

### D3 — Calculations as annotations (bezwaar deadlines)

`bezwaar-lifecycle/spec.md:88,95` prescribes "system SHALL automatically
calculate legal deadlines based on AWB articles 6:7, 6:8, 7:10, 7:24."
This is the textbook case for `x-openregister-calculations`.

**Decision:** Each AWB article becomes a calculation entry on the
bezwaar schema. `BezwaarDeadlineService` (if it exists or would have
been built) is not needed.

**Why:** Legal deadline rules are declarative, not procedural. Citing
the AWB article as the calculation key makes traceability to the legal
source explicit.

### D4 — ZGW protocol stays app-local

`NotificatieService.php`, `ZrcController::ZGW_API`,
`VERTROUWELIJKHEID_LEVELS`, `ZgwZtcRulesService::AFLEIDINGSWIJZE_*`,
`ZgwDrcRulesService` are all bindings to VNG ZGW protocol. The audit
explicitly lists these as legitimate app-local
(`04-hardcoded.md:144–152`).

**Decision:** Document the boundary. ZGW protocol code is NOT subsumed
into OR notification annotations. OR's `AnnotationNotificationDispatcher`
handles NC-internal notifications; `NotificatieService` handles outbound
ZGW Notificaties API messages.

**Why:** Future agents looking at procest's state-machine cleanup
might mistakenly fold ZGW notifications into OR — that would break
compliance with the published Dutch government standard.

### D5 — Manifest declares dependency on openconnector

ADR-024 mandates a manifest. procest depends on openconnector for ZGW
protocol bindings (Notificaties API outbound; ZRC/BRC/ZTC inbound for
sync).

**Decision:** `dependencies: ["openregister", "openconnector"]`.

**Why:** Without openconnector, procest's ZGW integration cannot
function. The manifest must accurately reflect runtime needs so the
hydra orchestrator can validate compatibility.

### D6 — Defer code changes to follow-up opsx changes

This change is spec-only. The PHP refactor (replacing
`ParaferingService.php:43-353`, deleting
`ParaferingNotificationService.php`, moving constants to admin-config,
fixing `getUserFolder('admin')` fallback) is deferred to a follow-up
implementation change.

**Why:** The audit task instruction is "spec-only — no code changes, no
PRs." Separating spec from code lets the spec be reviewed in isolation
and lets the code refactor reference the agreed annotation shape.

## Lifecycle annotation — before/after sketch

### Before (current — distributed across specs and PHP)

`lib/Service/ParaferingService.php:43-68`:
```php
public const STATUS_CONCEPT = 'concept';
public const STATUS_IN_PARAFERING = 'in_parafering';
public const STATUS_TERUGGESTUURD = 'teruggestuurd';
public const STATUS_GEPARAFEERD = 'geparafeerd';
public const STATUS_AANGEBODEN = 'aangeboden';
public const STATUS_BESLOTEN = 'besloten';
```

`lib/Service/ParaferingService.php:158-353` (illustrative — actual
method bodies are longer):
```php
public function startParafering(Voorstel $voorstel): Voorstel
{
    if ($voorstel->getStatus() !== self::STATUS_CONCEPT
        && $voorstel->getStatus() !== self::STATUS_TERUGGESTUURD) {
        throw new \RuntimeException('Cannot start parafering from status ' . $voorstel->getStatus());
    }
    $voorstel->setStatus(self::STATUS_IN_PARAFERING);
    $voorstel->setStartedAt(new \DateTime());
    // ... ~50 lines persisting + audit + notify ...
    return $voorstel;
}

public function acceptVoorstel(Voorstel $voorstel, string $userId): Voorstel
{
    if ($voorstel->getStatus() !== self::STATUS_IN_PARAFERING) {
        throw new \RuntimeException('Cannot accept from status ' . $voorstel->getStatus());
    }
    if (!$this->userCanParaferen($voorstel, $userId)) {
        throw new \AccessException('User may not parafer this case');
    }
    $voorstel->setStatus(self::STATUS_GEPARAFEERD);
    // ... record ParaFeeractie audit row ...
    // ... call ParaferingNotificationService->onAccept() ...
    return $voorstel;
}

public function rejectVoorstel(Voorstel $voorstel, string $userId, string $reason): Voorstel
{
    if ($voorstel->getStatus() !== self::STATUS_IN_PARAFERING) {
        throw new \RuntimeException('Cannot reject from status ' . $voorstel->getStatus());
    }
    $voorstel->setStatus(self::STATUS_TERUGGESTUURD);
    $voorstel->setRejectionReason($reason);
    // ... ParaferingNotificationService->onReject() ...
    return $voorstel;
}

// ... offerVoorstel(), recordDecision(), addAdHocStep() etc ...
```

Spec-side, the same machine is described prose-style in:
- `case-management/spec.md` (status transitions)
- `parafering-actions/spec.md` (action definitions)
- `parafering-audit-trail/spec.md` (audit recording)
- `parafeerroute-engine/spec.md` (route modification)

### After (target — single annotation)

```jsonc
// case schema (excerpt) — proposed x-openregister-lifecycle annotation
{
  "$id": "https://schemas.conduction.nl/procest/case.json",
  "type": "object",
  "x-openregister-lifecycle": {
    "field": "status",
    "initial": "concept",
    "states": {
      "concept": {
        "label": { "nl": "Concept", "en": "Draft" }
      },
      "in_parafering": {
        "label": { "nl": "In parafering", "en": "Pending review" }
      },
      "teruggestuurd": {
        "label": { "nl": "Teruggestuurd", "en": "Returned" }
      },
      "geparafeerd": {
        "label": { "nl": "Geparafeerd", "en": "Approved" }
      },
      "aangeboden": {
        "label": { "nl": "Aangeboden", "en": "Submitted" }
      },
      "besloten": {
        "label": { "nl": "Besloten", "en": "Decided" },
        "terminal": true
      }
    },
    "transitions": [
      {
        "action": "start_parafering",
        "label": { "nl": "Parafering starten", "en": "Start review" },
        "from": ["concept", "teruggestuurd"],
        "to": "in_parafering",
        "roles": ["zaakbehandelaar", "procest-admin"],
        "requires": [
          { "field": "title", "operator": "not-empty" },
          { "field": "documents", "operator": "min-count", "value": 1 }
        ],
        "audit": {
          "record": true,
          "context": ["actorType", "onBehalfOf", "comment"]
        }
      },
      {
        "action": "accept",
        "label": { "nl": "Paraferen", "en": "Approve" },
        "from": "in_parafering",
        "to": "geparafeerd",
        "roles": ["parafeerder"],
        "requires": [
          { "field": "currentStep.assignee", "operator": "equals", "value": "$user.id" }
        ],
        "audit": {
          "record": true,
          "context": ["actorType", "onBehalfOf", "comment"]
        }
      },
      {
        "action": "reject",
        "label": { "nl": "Terugsturen", "en": "Return" },
        "from": "in_parafering",
        "to": "teruggestuurd",
        "roles": ["parafeerder"],
        "requires": [
          { "field": "rejectionReason", "operator": "not-empty" }
        ],
        "audit": {
          "record": true,
          "context": ["actorType", "onBehalfOf", "comment", "rejectionReason"]
        }
      },
      {
        "action": "offer",
        "label": { "nl": "Aanbieden", "en": "Submit" },
        "from": "geparafeerd",
        "to": "aangeboden",
        "roles": ["zaakbehandelaar"],
        "audit": { "record": true }
      },
      {
        "action": "decide",
        "label": { "nl": "Besluit nemen", "en": "Record decision" },
        "from": "aangeboden",
        "to": "besloten",
        "roles": ["bestuurder"],
        "requires": [
          { "field": "decision", "operator": "not-empty" }
        ],
        "audit": {
          "record": true,
          "context": ["actorType", "onBehalfOf", "comment", "decision"]
        }
      },
      {
        "action": "skip_step",
        "label": { "nl": "Stap overslaan", "en": "Skip step" },
        "from": "in_parafering",
        "to": "in_parafering",
        "roles": ["procest-admin"],
        "requires": [
          { "field": "route.allowSkip", "operator": "equals", "value": true }
        ],
        "audit": { "record": true, "context": ["skippedStep", "comment"] }
      },
      {
        "action": "add_adhoc_step",
        "label": { "nl": "Stap toevoegen", "en": "Add step" },
        "from": "in_parafering",
        "to": "in_parafering",
        "roles": ["procest-admin", "zaakbehandelaar"],
        "audit": { "record": true, "context": ["addedStep", "reason"] }
      }
    ]
  }
}
```

### Code-side after (illustrative, deferred)

```php
// ParaferingService becomes a thin facade that translates Dutch domain
// action names to OR lifecycle transition keys. No more STATUS_* constants,
// no more `if status !== ...` blocks.
public function acceptVoorstel(Voorstel $voorstel, string $userId, ?string $comment = null): Voorstel
{
    // Engine handles: guard check, role check, audit write, notification
    // dispatch, persistence. ParaferingService is just the domain entry point.
    return $this->lifecycleEngine->transition(
        $voorstel,
        action: 'accept',
        context: ['actorType' => 'user', 'onBehalfOf' => $userId, 'comment' => $comment]
    );
}
```

### Mapping table — current spec sections → new home

| Old spec | Old section | New home |
|----------|-------------|----------|
| `case-management/spec.md` | Status transitions | `case-management/spec.md` § Lifecycle annotation |
| `parafering-actions/spec.md` | Action definitions | annotation `transitions[].action` |
| `parafering-actions/spec.md` | Action authorization | annotation `transitions[].roles` |
| `parafering-audit-trail/spec.md` | Immutable audit rows | OR `audit-trail-immutable` capability + annotation `audit.record` |
| `parafering-audit-trail/spec.md` | Delegation tracking | annotation `audit.context: [actorType, onBehalfOf]` |
| `parafeerroute-engine/spec.md` | Skip-step rules | annotation `transitions[action=skip_step]` |
| `parafeerroute-engine/spec.md` | Ad-hoc step rules | annotation `transitions[action=add_adhoc_step]` |
| `parafeerroute-engine/spec.md` | Route modification audit | annotation `audit.context: [skippedStep, addedStep]` |

## Notifications annotation — sketch

### Before

`lib/Service/ParaferingNotificationService.php` (illustrative — three
methods, ~200 LOC total):

```php
// :64
public function onParaferingStarted(Voorstel $voorstel): void
{
    foreach ($voorstel->getCurrentStep()->getAssignees() as $assignee) {
        $n = $this->notificationManager->createNotification();
        $n->setApp('procest')
          ->setUser($assignee)
          ->setSubject('parafering_pending', ['title' => $voorstel->getTitle()])
          ->setObject('voorstel', (string)$voorstel->getId())
          ->setLink($this->urlFor($voorstel));
        $this->notificationManager->notify($n);
    }
}

// :109 — onAccept(); :153 — onReject() — same shape, different subjects.
```

`lib/Service/CaseEmailService.php:92` — same anti-pattern via PHP-built
email subject.

### After

```jsonc
// case schema — x-openregister-notifications
{
  "x-openregister-notifications": [
    {
      "trigger": { "type": "lifecycle.transition", "action": "start_parafering" },
      "recipient": { "type": "role", "value": "currentStep.assignees" },
      "subject": { "i18nKey": "procest.notification.paraferingPending.subject" },
      "body":    { "i18nKey": "procest.notification.paraferingPending.body" },
      "link":    "/procest/case/{id}",
      "channel": ["nc-notification", "email"]
    },
    {
      "trigger": { "type": "lifecycle.transition", "action": "accept" },
      "recipient": { "type": "field", "value": "createdBy" },
      "subject": { "i18nKey": "procest.notification.accepted.subject" },
      "body":    { "i18nKey": "procest.notification.accepted.body" },
      "link":    "/procest/case/{id}",
      "channel": ["nc-notification", "email"]
    },
    {
      "trigger": { "type": "lifecycle.transition", "action": "reject" },
      "recipient": { "type": "field", "value": "createdBy" },
      "subject": { "i18nKey": "procest.notification.rejected.subject" },
      "body":    { "i18nKey": "procest.notification.rejected.body" },
      "context": { "rejectionReason": "$.rejectionReason" },
      "link":    "/procest/case/{id}",
      "channel": ["nc-notification", "email"]
    }
  ]
}
```

The annotation completely replaces both `ParaferingNotificationService`
and the PHP-built `CaseEmailService::setSubject()` path. i18n keys flow
through OR's `register-i18n` per ADR-025.

## Calculations annotation — bezwaar AWB deadlines

### Before

`openspec/specs/bezwaar-lifecycle/spec.md:88,95`:
> "The system SHALL automatically calculate legal deadlines based on AWB
> articles 6:7, 6:8, 7:10, 7:24."

(Implementation implied to be a `BezwaarDeadlineService` PHP class.)

### After

```jsonc
// bezwaar schema — x-openregister-calculations
{
  "x-openregister-calculations": [
    {
      "field": "termijnBezwaar",
      "label": { "nl": "Termijn bezwaar (AWB 6:7)", "en": "Objection deadline (AWB 6:7)" },
      "expression": "addBusinessDays($.besluitDatum, 42)",
      "legalSource": "AWB Art. 6:7"
    },
    {
      "field": "termijnVerschoonbaar",
      "label": { "nl": "Verschoonbare termijn (AWB 6:8)", "en": "Excusable deadline (AWB 6:8)" },
      "expression": "$.termijnBezwaar",
      "legalSource": "AWB Art. 6:8"
    },
    {
      "field": "termijnBeslissing",
      "label": { "nl": "Beslistermijn (AWB 7:10)", "en": "Decision deadline (AWB 7:10)" },
      "expression": "addWeeks($.bezwaarOntvangenDatum, 6)",
      "legalSource": "AWB Art. 7:10"
    },
    {
      "field": "termijnVerdaging",
      "label": { "nl": "Verdagingstermijn (AWB 7:24)", "en": "Postponement deadline (AWB 7:24)" },
      "expression": "addWeeks($.bezwaarOntvangenDatum, 12)",
      "legalSource": "AWB Art. 7:24"
    }
  ]
}
```

## Aggregations citations (case-dashboard, parafering-dashboard)

```jsonc
// case schema — x-openregister-aggregations
{
  "x-openregister-aggregations": [
    {
      "name": "casesByStatus",
      "groupBy": "status",
      "aggregate": "count"
    },
    {
      "name": "casesOverdue",
      "filter": { "termijnBeslissing": { "$lt": "$now" }, "status": { "$ne": "besloten" } },
      "aggregate": "count"
    }
  ]
}
```

`parafering-dashboard/spec.md` and `case-dashboard-view/spec.md` cite
this annotation; the dashboard widgets bind directly to OR's aggregation
endpoint.

## Boundary preserved — ZGW protocol stays app-local

| Component | Stays app-local | Reason |
|-----------|-----------------|--------|
| `lib/Service/NotificatieService.php` | YES | Dispatches outbound ZGW Notificaties API messages per VNG protocol. Not OR objects. |
| `lib/Controller/ZrcController.php` | YES | ZGW Zaken API — VNG REST contract. |
| `lib/Controller/BrcController.php` | YES | ZGW Besluiten API — VNG REST contract. |
| `lib/Controller/ZtcController.php` | YES | ZGW Catalogi API — VNG REST contract. |
| `lib/Controller/NrcController.php` | YES | ZGW Notificaties API — VNG REST contract. |
| `ZrcController::ZGW_API` constants | YES | API path identifiers per VNG. |
| `VERTROUWELIJKHEID_LEVELS` | YES | VNG-defined enum. |
| `ZgwZtcRulesService::AFLEIDINGSWIJZE_*` | YES | VNG-defined ZTC business rules. |
| `ZgwDrcRulesService` | YES | VNG-defined DRC business rules. |

Compare with what MOVES to OR annotations:

| Component | Moves to OR | Replacement |
|-----------|-------------|-------------|
| `ParaferingService.php:43-68` (STATUS_*) | YES | `x-openregister-lifecycle.states` |
| `ParaferingService.php:158-353` (transitions) | YES | `x-openregister-lifecycle.transitions` |
| `ParaferingNotificationService.php` (3 methods) | YES | `x-openregister-notifications` |
| `CaseEmailService::setSubject()` | YES | `x-openregister-notifications.subject.i18nKey` |
| `BezwaarDeadlineService` (implied) | YES | `x-openregister-calculations` |
| Bespoke parafeeractie versioning | YES | OR `audit-trail-immutable` |
| Custom dashboard count query | YES | `x-openregister-aggregations` |

## Manifest sketch

```jsonc
// procest/manifest.json (sketched; created in follow-up code change)
{
  "$schema": "https://schemas.conduction.nl/hydra/app-manifest.json",
  "id": "procest",
  "name": { "nl": "Procest", "en": "Procest" },
  "tier": 2,
  "version": "0.x",
  "dependencies": ["openregister", "openconnector"],
  "consumes": [
    "openregister.object-lifecycle",
    "openregister.audit-trail-immutable",
    "openregister.notificatie-engine",
    "openregister.aggregations-backend-native",
    "openregister.geo-metadata-kaart",
    "openregister.register-i18n",
    "openregister.computed-fields",
    "openregister.register-resolver-service",
    "nextcloud-vue.multi-tenancy-context",
    "hydra.i18n-source-of-truth",
    "hydra.i18n-api-language-negotiation"
  ],
  "provides": [
    "procest.case-schema",
    "procest.parafering-lifecycle",
    "procest.zgw-bindings"
  ],
  "routes": "appinfo/routes.php"
}
```

`tier: 2` reflects current state (some custom code remains for ZGW
protocol bindings). After the follow-up implementation change lands —
when `ParaferingService` is a thin facade over OR's lifecycle engine —
procest can be promoted to tier 3.

## ADR alignment

- **ADR-022 (apps consume OR abstractions)** — directly satisfied:
  lifecycle, notifications, calculations, aggregations, audit, i18n,
  resolver — all annotation-based.
- **ADR-023 (action authorization)** — satisfied via
  `transitions[].roles` in the lifecycle annotation.
- **ADR-024 (app manifest)** — manifest sketched; follow-up change
  creates the file.
- **ADR-025 (i18n source of truth)** — all user-facing strings in
  annotations use `i18nKey` references. No PHP-built copy survives.

## Risks (re-stated from proposal with mitigations)

- **R1 OR lifecycle engine maturity** — annotation must support
  guards, role auth, audit-recording listener. Tracked above; if a gap,
  raise an OR-side change before code refactor.
- **R2 Information loss in retirement** — Phase 5 mapping table
  ensures every requirement from the four old specs has a new home.
- **R3 Notification annotation gaps** — three call sites map cleanly;
  if rich attachments / multi-channel needs surface, OR side gets a
  feature request, this change is amended.
- **R4 NotificatieService confusion** — D4 + Phase 13 explicit
  boundary documentation.
- **R5 Tenant-unsafe seed** — Phase 9.5 audits and flags;
  multi-tenant fix is its own change.

## Acceptance (design-side)

- [ ] Lifecycle annotation example covers all 6 STATUS_* values,
  all transitions in `ParaferingService.php:158-353`, and all
  skip-step / ad-hoc rules from `parafeerroute-engine`.
- [ ] Notifications annotation example covers all 3 call sites in
  `ParaferingNotificationService` + `CaseEmailService::setSubject()`.
- [ ] Calculations annotation example covers AWB 6:7, 6:8, 7:10, 7:24.
- [ ] Aggregations annotation cited by dashboards.
- [ ] Boundary table (D4 + § "Boundary preserved") is unambiguous.
- [ ] Manifest sketch lists openregister + openconnector dependencies.
- [ ] No code is modified.
