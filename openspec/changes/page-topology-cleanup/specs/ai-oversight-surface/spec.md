# ai-oversight-surface

## ADDED Requirements

### Requirement: AI oversight is owned by hermiq

Human oversight of AI — the EU AI Act Art. 14 record of what a model proposed
and what a human did with it — SHALL be administered in hermiq. Procest SHALL
NOT declare AI-oversight index or detail pages.

Procest SHALL deliver each decision to hermiq by dispatching
`OCA\Hermiq\Event\AiOversightRecordedEvent`. It SHALL NOT write into hermiq's
register directly (ADR-041), and SHALL resolve the event class by name so
procest remains installable without hermiq.

#### Scenario: Procest hosts no AI-oversight pages

- **GIVEN** the procest manifest
- **THEN** neither `/settings/ai-oversight` nor `/settings/ai-oversight/:id` exists
- **AND** no NAVIGATION entry links to hermiq's oversight surface
- **AND** exactly one `section: "integrations"` entry links to it, gated on `visibleIf.appInstalled: "hermiq"`

> **Amended 2026-08-26 by ADR-110.** This scenario previously required *"exactly
> one navigation entry links to hermiq's oversight surface"*. A link that leaves
> this app for another one is not a page of this app — it can never be the active
> route and carries no counter — so it renders in the Integrations section of the
> per-user settings modal instead. The entry is relocated, never dropped, so
> ADR-044 Decision 5 still holds. The `appInstalled` gate is new: without it the
> section advertises a guaranteed 404 on an instance with no hermiq.

#### Scenario: A human decision reaches hermiq

- **GIVEN** a handler accepts, rejects or modifies an AI suggestion on a case
- **THEN** procest dispatches an oversight record naming `originApp: procest`, the case as subject, and the human's action
- **AND** hermiq records it as an advisory Approval

### Requirement: Procest's vocabulary is translated, not flattened

Procest records a human action as `accepted`, `rejected` or `modified`. It SHALL
map `modified` onto hermiq's `overridden`, so a correction is not filed as a
rejection.

#### Scenario: A corrected suggestion is not recorded as a rejection

- **GIVEN** a handler changes an AI suggestion before applying it
- **THEN** the delegated record carries `humanAction: "overridden"`
- **AND** both the model's suggestion and the value the human used are carried

### Requirement: Only decisions are delegated

Procest's audit log holds both model-invocation entries (`action: "suggestion"`,
no human involved yet) and human decisions. Only the decisions are Art. 14
oversight evidence, and only those SHALL be delegated. Model-invocation entries
SHALL remain in procest's own log.

#### Scenario: A suggestion-only entry is not sent

- **GIVEN** an audit entry recording that the model ran, with no `userAction`
- **THEN** no oversight record is dispatched

### Requirement: An audit outage never becomes a functional one

Delegation SHALL NOT throw and SHALL NOT fail the handler's action. By the time
a decision is delegated the human has already acted and the case has already
moved on. Procest SHALL keep writing its own local copy, so an instance without
hermiq still has an oversight trail.

#### Scenario: Hermiq is not installed

- **GIVEN** an instance where hermiq is absent
- **WHEN** a handler decides on an AI suggestion
- **THEN** the action completes normally
- **AND** the decision is still recorded in procest's own audit log
- **AND** the response reports that it was not centrally recorded

### Requirement: Existing oversight decisions are migrated, not stranded

Decisions already recorded before this change SHALL be replayed to hermiq
through the same event new decisions travel, by a repair step in procest. Hermiq
SHALL NOT read procest's register. The replay SHALL be idempotent and SHALL NOT
fail an upgrade.

#### Scenario: Existing decisions appear in hermiq

- **GIVEN** procest's audit log holds decisions recorded before this change
- **WHEN** the upgrade runs
- **THEN** each of them is delegated to hermiq
- **AND** running the upgrade again does not duplicate them

#### Scenario: A failed replay does not fail the upgrade

- **GIVEN** hermiq is absent or its register is unreachable
- **WHEN** the repair step runs
- **THEN** it reports what it skipped and the upgrade completes
