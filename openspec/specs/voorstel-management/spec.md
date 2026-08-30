---
status: retired
retired_in: dossiq-adopt-or-abstractions
canonical_home: case-management/spec.md
---

> **RETIRED — voorstel folded into the consolidated case lifecycle.**
>
> In the OR-abstractions consolidation the `voorstel` entity was
> absorbed into the case schema: a case in `concept` → `in_parafering`
> → `teruggestuurd` → `geparafeerd` → `aangeboden` → `besloten`
> **is** the voorstel. The schema, status lifecycle, detail view,
> create-from-case flow, and per-case multiplicity (deelzaak) all
> live on the case entity now, with role-based transition
> authorization expressed in the `x-openregister-lifecycle`
> annotation. See ADR-022 and `case-management/spec.md`.
>
> This file is preserved as a historical appendix. Refer to
> `case-management/spec.md` for canonical voorstel semantics.

## Purpose

@e2e exclude RETIRED spec; requirements consolidated into case-management/spec.md.

## REQ migration map

| Retired REQ here | New home in `case-management/spec.md` |
|------------------|----------------------------------------|
| Voorstel Schema Registration | Case Entity data model + lifecycle states (`concept`..`besloten`) on the case schema. |
| Create Voorstel from Case | REQ-CM-01 Case Creation (with case-type-driven defaults). |
| Voorstel Status Lifecycle | REQ-CM-14 Status Change + consolidated `x-openregister-lifecycle` annotation; `terugsturen` / `paraferen` / `aanbieden` are guarded transitions. |
| Multiple Voorstellen per Case | REQ-CM-18 Sub-Cases (deelzaak) — each B&W-voorstel that needs an independent route is modeled as a deelzaak under its parent case. |
| Voorstel Detail View | REQ-CM-06 Case Detail View — including parafering progress timeline (REQ-CM-07) and audit trail (REQ-CM-22). |

## Requirements

### Requirement: Voorstel Schema Registration

The system SHALL register a `voorstel` schema in the Dossiq OpenRegister configuration with properties: case (reference), type (enum: dt_advies, collegeadvies, raadsvoorstel), onderwerp (string), steller (string, user UID), afdeling (string), portefeuillehouder (string, user UID), status (enum: concept, in_parafering, ter_accordering, geaccordeerd, aangeboden, besloten, gearchiveerd, teruggestuurd), parafeerroute (reference), currentStep (integer), document (string, Nextcloud file ID), bijlagen (array of strings), behandeling (enum: hamerstuk, bespreekstuk).

**Feature tier**: V1
**Schema.org type**: `schema:CreativeWork`
**ZGW mapping**: No direct ZGW equivalent; bridges to `Besluit` at completion
**CMMN concept**: Stage (within CasePlanModel)

#### Scenario: Schema is available after app install

- **WHEN** the Dossiq app is installed or updated
- **THEN** the `voorstel` schema SHALL be registered in the Dossiq register via the repair step
- **AND** the schema SHALL enforce required properties: case, type, onderwerp, steller, status

### Requirement: Create Voorstel from Case

The system SHALL support creating a B&W-voorstel from within a case context. The voorstel SHALL be linked to the parent case and pre-filled with case metadata.

**Feature tier**: V1

#### Scenario: Create collegeadvies voorstel

- **WHEN** the steller clicks "Nieuw B&W-voorstel" on the case detail view for case "Bestemmingsplan Centrum"
- **THEN** the system SHALL create a voorstel object in OpenRegister with status "concept"
- **AND** the voorstel SHALL have onderwerp pre-filled from the case title
- **AND** the voorstel SHALL have steller set to the current user UID
- **AND** the voorstel SHALL have afdeling and portefeuillehouder loaded from the case type configuration

#### Scenario: Select voorstel type

- **WHEN** the steller creates a new voorstel
- **THEN** the steller SHALL select the voorstel type from: "DT-advies", "Collegeadvies", "Raadsvoorstel"
- **AND** the selected type SHALL determine which default parafeerroute is loaded from the case type configuration

#### Scenario: Attach document to voorstel

- **WHEN** the steller creates or edits a voorstel
- **THEN** the steller SHALL be able to attach a primary voorstel document (Nextcloud file ID)
- **AND** the steller SHALL be able to attach additional bijlagen (Nextcloud file IDs)
- **AND** existing case documents SHALL be selectable as bijlagen

### Requirement: Voorstel Status Lifecycle

The system SHALL manage the voorstel through a defined status lifecycle: concept -> in_parafering -> ter_accordering -> geaccordeerd -> aangeboden -> besloten -> gearchiveerd. The status "teruggestuurd" is a return-to-steller state from any parafering step.

**Feature tier**: V1

#### Scenario: Submit voorstel for parafering

- **WHEN** the steller submits a voorstel with status "concept" that has a document and parafeerroute attached
- **THEN** the voorstel status SHALL change to "in_parafering"
- **AND** the currentStep SHALL be set to 1
- **AND** the first actor in the parafeerroute SHALL receive a Nextcloud notification

#### Scenario: Return voorstel to steller

- **WHEN** a parafeerder returns the voorstel (action: terugsturen)
- **THEN** the voorstel status SHALL change to "teruggestuurd"
- **AND** the steller SHALL receive a notification with the return comment
- **AND** the steller SHALL be able to edit the document and resubmit (resuming from the returning step)

#### Scenario: Voorstel reaches final endorsement

- **WHEN** the last step in the parafeerroute is completed (all required endorsements received)
- **THEN** the voorstel status SHALL change to "geaccordeerd"

### Requirement: Multiple Voorstellen per Case

The system SHALL support multiple voorstellen on a single case, each with independent status and parafeerroute.

**Feature tier**: V1

#### Scenario: Second voorstel on a case

- **WHEN** a case already has a "DT-advies" voorstel with status "besloten"
- **AND** the steller creates a new "Collegeadvies" voorstel
- **THEN** both voorstellen SHALL be visible on the case detail
- **AND** each voorstel SHALL have its own independent status and parafeerroute

### Requirement: Voorstel Detail View

The system SHALL provide a dedicated detail view for a voorstel showing header metadata, document preview, parafering progress, action history, and case reference.

**Feature tier**: V1

#### Scenario: View voorstel detail

- **WHEN** an authorized user opens the voorstel detail view
- **THEN** the view SHALL display: onderwerp, type, steller, afdeling, status, portefeuillehouder
- **AND** the document SHALL be viewable inline (PDF preview or Nextcloud viewer link)
- **AND** all bijlagen SHALL be listed and downloadable
- **AND** the parafering progress SHALL show a visual step indicator (completed/current/future)
- **AND** the action history SHALL show all parafeeracties with timestamps, actors, and comments
- **AND** a link back to the parent case SHALL be displayed

#### Scenario: Action buttons per role

- **WHEN** the current user is the active parafeerder at the current step
- **THEN** the voorstel detail SHALL show action buttons: "Paraferen" and "Terugsturen"
- **AND** if the step type is "advies", the button SHALL be "Adviseren" instead of "Paraferen"
- **AND** if the user is NOT the active actor, action buttons SHALL NOT be visible

#### Scenario: Progress timeline visualization

- **WHEN** a voorstel has 5 steps where steps 1-3 are completed, step 4 is active, step 5 is pending
- **THEN** the progress indicator SHALL show steps 1-3 as completed (green checkmark, actor name, date)
- **AND** step 4 SHALL show as active (blue indicator, actor name, "Wachtend")
- **AND** step 5 SHALL show as pending (grey indicator, actor name)
