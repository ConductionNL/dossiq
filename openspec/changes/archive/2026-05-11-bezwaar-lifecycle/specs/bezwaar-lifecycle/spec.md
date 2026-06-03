## ADDED Requirements

### Requirement: Bezwaar Case Type Pre-Seeded Configuration (REQ-BL-1)

The system SHALL provide a pre-seeded "Bezwaar" (objection) case type with Awb hoofdstuk 6/7 compliant configuration. The case type SHALL be imported via the repair step alongside existing case types and SHALL declare statutory deadlines via OpenRegister `x-openregister-calculations` (per ADR-022).

**Feature tier**: V1
**ZGW mapping**: `zaaktype` with `omschrijving` "Bezwaar"
**CMMN mapping**: CaseDefinition with TimerEventListeners for legal deadlines

| Property | Value |
|----------|-------|
| `title` | Bezwaar |
| `description` | Bezwaarprocedure conform Awb hoofdstuk 6 en 7 |
| `processingDeadline` | P6W (Awb art. 7:10 lid 1) |
| `extensionAllowed` | true |
| `extensionPeriod` | P6W (Awb art. 7:10 lid 3) |
| `suspensionAllowed` | true |
| `origin` | external |
| `trigger` | Bezwaarschrift van belanghebbende |

#### Scenario: Bezwaar case type is available after installation

- **WHEN** the Procest app repair step runs
- **THEN** a case type "Bezwaar" SHALL exist in the procest register
- **AND** the case type SHALL have `processingDeadline = P6W`, `extensionAllowed = true`, `extensionPeriod = P6W`, `suspensionAllowed = true`
- **AND** the case schema SHALL declare `x-openregister-calculations` rules for `ontvangstbevestigingDeadline`, `afhandelDeadline`, and `dwangsomStartDate`

#### Scenario: Deadline rules use OR computed fields, not procest service

- **WHEN** a developer inspects how deadlines are calculated
- **THEN** the formulas SHALL live in the case schema's `x-openregister-calculations` annotation
- **AND** there SHALL NOT be a procest-specific `BezwaarDeadlineService` that duplicates the logic
- **AND** the implementation SHALL match ADR-022 (procest-adopt-or-abstractions)

### Requirement: Bezwaar Status State Machine (REQ-BL-2)

The system SHALL enforce a strict state machine over bezwaar case status, with eight ordered statuses, two terminal-only statuses, and explicit transition rules grounded in Awb articles.

**Feature tier**: V1
**ZGW mapping**: `statustype` linked to the bezwaar `zaaktype`

| Order | Status | Awb |
|-------|--------|-----|
| 1 | Ontvangen | 6:4 |
| 2 | Ontvankelijkheidstoets | 6:5, 6:6 |
| 3 | In behandeling | 7:2 |
| 4 | Hoorzitting gepland | 7:2 |
| 5 | Hoorzitting afgerond | 7:7 |
| 6 | Advies uitgebracht | 7:13 |
| 7 | Beslissing op bezwaar | 7:11, 7:12 |
| 8 | Afgehandeld | — |
| — | Niet-ontvankelijk (terminal) | 6:6 |
| — | Ingetrokken (terminal) | 6:21 |

#### Scenario: All 10 status types are seeded

- **WHEN** the repair step completes
- **THEN** exactly 10 status types SHALL exist for the Bezwaar case type
- **AND** the 8 non-terminal statuses SHALL be ordered 1..8
- **AND** `Niet-ontvankelijk` and `Ingetrokken` SHALL be flagged as terminal

#### Scenario: Backward transitions are rejected

- **WHEN** a behandelaar attempts to transition a bezwaar case from `In behandeling` back to `Ontvangen`
- **THEN** the API SHALL respond with HTTP 422 and message `"Backward transitions are not permitted"`
- **AND** the case status SHALL remain unchanged

#### Scenario: Skip hearing when hoorrecht is waived

- **WHEN** the linked `objection.hearingWaived` is `true` with a non-empty `waiverReason`
- **THEN** the case SHALL be allowed to transition from `Ontvankelijkheidstoets` or `In behandeling` directly to `Advies uitgebracht` or `Beslissing op bezwaar`
- **AND** the audit entry SHALL record `reason = "Belanghebbende heeft afgezien van het recht te worden gehoord"`

### Requirement: Bezwaar Role Types (REQ-BL-3)

The system SHALL provide seven pre-seeded role types for the Bezwaar case type covering all Awb-recognized participants.

**Feature tier**: V1
**ZGW mapping**: `roltype` linked to bezwaar `zaaktype`

| Role Type | Generic Role |
|-----------|-------------|
| Bezwaarmaker | initiator |
| Behandelaar bezwaar | handler |
| Voorzitter commissie | decision_maker |
| Lid commissie | advisor |
| Secretaris commissie | coordinator |
| Vertegenwoordiger | stakeholder |
| Primair beslisser | advisor |

#### Scenario: All 7 role types are seeded

- **WHEN** the repair step completes
- **THEN** 7 role types SHALL exist for the Bezwaar case type
- **AND** each role type SHALL map to one of the standard generic roles

### Requirement: Statutory Deadline Calculation (REQ-BL-4)

The system SHALL automatically calculate Awb-mandated deadlines on case create/save via OpenRegister `x-openregister-calculations` (ADR-022), with no procest-specific deadline service.

**Feature tier**: V1
**Awb reference**: art. 6:7, 6:8, 7:10, 7:24

| Trigger | Output | Formula | Awb |
|---------|--------|---------|-----|
| Case created | `ontvangstbevestigingDeadline` | `ontvangstdatum + P1W` | 6:14 |
| Case created | `afhandelDeadline` | `ontvangstdatum + P6W` | 7:10 lid 1 |
| Verdaging recorded | `afhandelDeadline` | `afhandelDeadline + P6W` | 7:10 lid 3 |
| Opschorting end | `afhandelDeadline` | `+ (clockStart − clockStop)` | 7:10 lid 4 |
| Ingebrekestelling | `dwangsomStartDate` | `ingebrekestellingDate + P2W` | 4:17 |

#### Scenario: Initial deadlines on case creation

- **WHEN** a bezwaar case is created with `ontvangstdatum = 2026-03-02` (Monday)
- **THEN** `ontvangstbevestigingDeadline` SHALL be `2026-03-09`
- **AND** `afhandelDeadline` SHALL be `2026-04-13`
- **AND** these values SHALL be persisted on the case object

#### Scenario: Deadline warning within 5 working days

- **WHEN** a non-terminal bezwaar case is within 5 working days of `afhandelDeadline`
- **THEN** the case SHALL appear in the dashboard "at-risk" section with a yellow flag
- **AND** the behandelaar SHALL receive a notification `"Bezwaartermijn nadert"`

### Requirement: Ontvankelijkheidstoets (REQ-BL-5)

The system SHALL support the formal admissibility check (ontvankelijkheidstoets) covering timeliness (Awb 6:7-6:8), belanghebbendheid (Awb 1:2), and vormvereisten (Awb 6:5-6:6).

**Feature tier**: V1

#### Scenario: Timeliness check defaults to false on late submission

- **WHEN** the behandelaar opens the ontvankelijkheidstoets on a bezwaar where `receivedDate − contestedDecision.publicationDate > 6 weeks`
- **THEN** `objection.isTimely` SHALL default to `false`
- **AND** a warning `"Bezwaartermijn mogelijk overschreden"` SHALL be displayed
- **AND** the behandelaar SHALL be able to override with a `timelinessAssessment` reason (e.g. verschoonbare termijnoverschrijding per Awb 6:11)

#### Scenario: Niet-ontvankelijk transition requires motivering

- **WHEN** a behandelaar transitions a case from `Ontvankelijkheidstoets` to `Niet-ontvankelijk`
- **THEN** the API SHALL require a non-empty `timelinessAssessment` OR `vormvereisteReason` OR `belanghebbendheidReason` on the linked `objection`
- **AND** absence SHALL be rejected with HTTP 422

### Requirement: Verdaging and Opschorting (REQ-BL-6)

The system SHALL support the statutory extension (verdaging, Awb 7:10 lid 3) and suspension (opschorting, Awb 7:10 lid 4) mechanisms, with audit entries and bezwaarmaker notification.

**Feature tier**: V1

#### Scenario: Verdaging extends afhandelDeadline by 6 weeks

- **WHEN** the behandelaar records a verdaging on a case with `afhandelDeadline = 2026-04-13`
- **THEN** `afhandelDeadline` SHALL be recomputed to `2026-05-25`
- **AND** the extension SHALL be logged in the audit trail with `awbReference = "Awb 7:10 lid 3"`
- **AND** the bezwaarmaker SHALL be notified per Awb art. 7:10 lid 3

#### Scenario: Opschorting stops and resumes the clock

- **WHEN** the behandelaar records opschorting with `opschortingStart = 2026-04-01`
- **AND** later `opschortingEnd = 2026-04-15`
- **THEN** `afhandelDeadline` SHALL be extended by 14 days
- **AND** start and end SHALL be logged with `awbReference = "Awb 7:10 lid 4"`

### Requirement: Ingebrekestelling and Dwangsom (REQ-BL-7)

The system SHALL track ingebrekestelling notices and accrue automatic dwangsom liability under Wet dwangsom (Awb 4:17) when the bestuursorgaan misses the bezwaardeadline.

**Feature tier**: V1
**Awb reference**: art. 4:17, 4:18

#### Scenario: Ingebrekestelling starts 14-day grace clock

- **WHEN** a bezwaarmaker submits an ingebrekestelling on `2026-04-20`
- **THEN** the case SHALL store `ingebrekestellingDate = 2026-04-20` and `dwangsomStartDate = 2026-05-04`
- **AND** a red banner SHALL appear on the case detail view showing the 14-day grace clock

#### Scenario: Dwangsom accrues automatically after grace period

- **WHEN** no beslissing op bezwaar is recorded before `dwangsomStartDate`
- **THEN** the system SHALL begin accruing dwangsom per Awb 4:17 schedule (€ 23/day day 1-14, € 35/day day 15-28, € 45/day day 29-42, capped at € 1 442)
- **AND** the running `dwangsomAccrued` SHALL be visible on the case detail and dashboard
- **AND** an escalation notification SHALL be sent to teamlead + bezwaar-coördinator

### Requirement: Sister-Capability Integration (REQ-BL-8)

The system SHALL wire the bezwaar-lifecycle to the three sister capabilities (`bezwaar-hearing`, `bezwaar-advisory-committee`, `bezwaar-decision`) via OpenRegister object-event listeners — no direct controller-to-controller calls.

**Feature tier**: V1

#### Scenario: Hearing completion transitions case status

- **WHEN** a `hearingSession` linked to a bezwaar case is updated with `status = uitgevoerd`
- **THEN** an event listener SHALL transition the bezwaar case status to `Hoorzitting afgerond`
- **AND** the audit entry SHALL record `awbReference = "Awb 7:7"`

#### Scenario: Advisory report transitions case status

- **WHEN** an `advisoryReport` linked to a bezwaar case is created
- **THEN** an event listener SHALL transition the bezwaar case status to `Advies uitgebracht`
- **AND** if `deviationFromPrimaryDecision = true`, a behandelaar-attention flag SHALL be raised

#### Scenario: Niet-ontvankelijk decision short-circuits the lifecycle

- **WHEN** a `decision` with `besluittype = "Beslissing op bezwaar"` and `dispositionType = niet_ontvankelijk` is recorded
- **THEN** an event listener SHALL transition the bezwaar case status directly to `Niet-ontvankelijk` (skipping intermediate statuses)
- **AND** the audit entry SHALL record `awbReference = "Awb 6:6"`

### Requirement: Audit Trail and Immutability (REQ-BL-9)

Every status transition on a bezwaar case SHALL produce an immutable, append-only audit entry; transitions that change legal posture SHALL require an `awbReference`.

**Feature tier**: V1

#### Scenario: Audit entry shape

- **WHEN** a status transition is committed
- **THEN** the entry SHALL contain `actor` (NC UID), `fromStatus`, `toStatus`, `reason`, `awbReference`, `timestamp`
- **AND** the entry SHALL be written via OpenRegister's per-save audit log
- **AND** the entry SHALL NOT be editable or deletable by any user role

#### Scenario: Legal-posture transitions require Awb reference

- **WHEN** a transition involves Ontvankelijkheidstoets outcome, verdaging, opschorting, hoorrecht-afzien, niet-ontvankelijk, or intrekking
- **AND** the `awbReference` field is empty or missing
- **THEN** the API SHALL reject the request with HTTP 422 and message `"Awb reference required for this transition"`

### Requirement: Vue Lifecycle View (REQ-BL-10)

The frontend SHALL provide a dedicated bezwaar lifecycle view rendering status timeline, deadline countdown badges, transition guard messages, and ingebrekestelling/dwangsom banners.

**Feature tier**: V1

#### Scenario: Status timeline reflects the 10 status types

- **WHEN** a user opens the bezwaar lifecycle view for a case in `Hoorzitting gepland`
- **THEN** the timeline SHALL display all 10 status types in order
- **AND** statuses up to and including the current one SHALL be marked completed/active
- **AND** terminal statuses SHALL be visually distinct (greyed if not reached)

#### Scenario: Deadline countdown badges follow traffic-light rule

- **WHEN** the view renders `afhandelDeadline`
- **THEN** the badge SHALL be green when > 5 working days remain
- **AND** yellow when ≤ 5 working days remain
- **AND** red when the deadline has passed

#### Scenario: Disallowed transitions are hidden from the action menu

- **WHEN** a behandelaar opens the transition action menu on a case in `Ontvangen`
- **THEN** only `Ontvankelijkheidstoets` SHALL be offered as a target
- **AND** transitions to later or earlier statuses SHALL NOT appear in the menu

#### Scenario: Dwangsom banner shows live counter

- **WHEN** `dwangsomAccrued > 0` on the case
- **THEN** the lifecycle view SHALL display a red banner with the current accrued amount, the days elapsed since `dwangsomStartDate`, and the cap of € 1 442
