## ADDED Requirements

### Requirement: Bezwaaradviescommissie Entity and Composition

The system SHALL register a `bezwaaradviescommissie` schema modeling an independent advisory committee. A committee SHALL declare `name`, `domain` (optional jurisdiction filter), `chair` (the voorzitter), `members` (an array of party references, length ≥ 2 in addition to the chair, satisfying the Awb Art. 7:13(1) "ten minste drie leden" baseline), `secretary` (a council civil servant per Awb Art. 7:13(2)), `quorum` (integer ≥ 2, default 3), `term_starts_on`, `term_ends_on`, and `status` (enum: `active`, `archived`). The committee entity SHALL be a long-lived configuration object — independent of any single bezwaar case — and SHALL be the only entity authorised to issue an `advice` deliverable under this capability.

**Feature tier**: V1
**Schema.org type**: `schema:Organization` (specialisation: `schema:GovernmentOrganization`)
**ZGW mapping**: no direct ZGW equivalent; the advice deliverable maps loosely to `Adviesdocument`.
**Awb reference**: Art. 7:13(1)–(2)

#### Scenario: Committee creation rejects sub-minimum composition

- **GIVEN** an administrator creates a committee with `chair = "j.smit"` and `members = ["j.smit"]` (chair only)
- **WHEN** the create request is validated
- **THEN** the system SHALL reject the request with HTTP 422
- **AND** the response SHALL carry a Dutch message such as `Een bezwaaradviescommissie heeft een voorzitter plus ten minste twee leden nodig (Awb Art. 7:13)`
- **AND** no committee record SHALL be persisted

#### Scenario: Archived committee cannot be assigned new bezwaren

- **GIVEN** committee C1 has `status = archived`
- **WHEN** a bezwaar handler tries to assign a new bezwaar case to C1
- **THEN** the system SHALL reject the assignment with HTTP 409
- **AND** the response SHALL state that the committee is no longer active

### Requirement: Member Independence Check at Assignment

When a bezwaar case is assigned to a committee (transition `assigned → in-deliberation` on the advice request), the system SHALL verify that **no panel member is a civil servant who was involved in the contested primair besluit**, per Awb Art. 7:13(3). The check SHALL compare each panel member's `nc_uid` against the `steller` and any signatories recorded on the primair besluit. A failing match SHALL block the transition; the bezwaar handler SHALL be required to swap the conflicting member or re-route the case to a different committee.

**Feature tier**: V1
**Awb reference**: Art. 7:13(3)

#### Scenario: Panel member who signed the primair besluit is rejected

- **GIVEN** a bezwaar case against besluit B1 where `B1.steller = "j.devries"`
- **AND** committee C1 with `panel = ["a.bakker", "p.janssen", "j.devries"]`
- **WHEN** the bezwaar handler tries to advance the advice request to `in-deliberation`
- **THEN** the system SHALL reject the transition with HTTP 409
- **AND** the response SHALL identify `j.devries` as the conflicting member with reason `Lid was betrokken bij het bestreden besluit (Awb Art. 7:13 lid 3)`
- **AND** the advice request SHALL remain in `assigned`
- **AND** an `independence-check-failed` entry SHALL be appended to `bac_audit_trail`

#### Scenario: Independence is re-evaluated per case

- **GIVEN** committee C1 with `panel = ["a.bakker", "p.janssen", "j.devries"]` is valid for bezwaar #A123 (whose primair besluit was signed by `m.kuipers`)
- **WHEN** the same committee composition is proposed for bezwaar #A124 (whose primair besluit was signed by `j.devries`)
- **THEN** the system SHALL accept the panel for #A123 and reject it for #A124
- **AND** the rejection on #A124 SHALL not affect the validity of #A123

### Requirement: Advice Request Lifecycle

The system SHALL maintain a `bac_advice_request` per bezwaar-to-committee referral through a three-state lifecycle: `assigned`, `in-deliberation`, `advice-issued`. The default state on creation SHALL be `assigned`. Transitions SHALL be driven exclusively by the `BacAdviceRequestService` (or equivalent) and SHALL never be applied via raw schema writes from the frontend. The lifecycle SHALL be one-way (no reverse transitions); a withdrawn bezwaar leaves the advice request in its last state for audit.

**Feature tier**: V1
**Awb reference**: Art. 7:13(1), 7:24

#### Scenario: Default state on creation

- **WHEN** a bezwaar handler creates a `bac_advice_request` for bezwaar case Z1 referring it to committee C1
- **THEN** the new advice request SHALL have `status = assigned`
- **AND** `assigned_at` SHALL be the server timestamp at creation
- **AND** `deadline` SHALL default to `assigned_at + 12 weeks` per Awb Art. 7:24(1)

#### Scenario: Transition to in-deliberation requires hearing report

- **GIVEN** an advice request in state `assigned` with no `hearing_report_ref`
- **WHEN** the bezwaar handler tries to advance to `in-deliberation`
- **THEN** the system SHALL reject the transition with HTTP 409
- **AND** the message SHALL state that a hoorzittingverslag from the parent bezwaar-lifecycle is required

#### Scenario: Transition to advice-issued is irreversible

- **GIVEN** an advice request in state `advice-issued`
- **WHEN** any caller attempts to set `status` back to `in-deliberation`
- **THEN** the system SHALL reject the request with HTTP 409
- **AND** the advice request `status` SHALL remain `advice-issued`

### Requirement: Advice Document Content Contract

The advice document produced by the committee SHALL satisfy the content contract of Awb Art. 7:13(7). The document SHALL be a Nextcloud file with a JSON sidecar exposing the structured fields `findings` (string, ≥ 50 chars), `hearing_summary_ref` (pointer to the hoorzittingverslag in the parent bezwaar-lifecycle), `legal_assessment` (string, ≥ 50 chars), `conclusion` (enum: `gegrond`, `ongegrond`, `gedeeltelijk_gegrond`, `niet_ontvankelijk`), `recommendation` (string, non-empty), `dissenting_opinions` (optional array of `{ member_uid, opinion }` blocks), `signed_by_chair_at` (datetime), and `signature_evidence` (Nextcloud file ID or evidence reference). The transition `in-deliberation → advice-issued` SHALL be blocked if any required field is missing or empty.

**Feature tier**: V1
**Awb reference**: Art. 7:13(7)

#### Scenario: Missing legal_assessment blocks advice-issued

- **GIVEN** an advice request in `in-deliberation` with a draft advice document where `legal_assessment` is empty
- **WHEN** the chair tries to sign and transition to `advice-issued`
- **THEN** the transition SHALL be rejected with HTTP 422
- **AND** the response SHALL list `legal_assessment` as the missing required field
- **AND** the advice request SHALL remain in `in-deliberation`

#### Scenario: Conclusion outside enum is rejected

- **GIVEN** a draft advice document with `conclusion = "deels_ontvankelijk"` (not in the enum)
- **WHEN** the chair signs the advice
- **THEN** OpenRegister SHALL reject the object with a schema validation error
- **AND** the advice request SHALL remain in `in-deliberation`

### Requirement: Council Deviation Justification (Motivatie Afwijking Advies)

When the council issues a `besluit op bezwaar` and the besluit's outcome diverges from the linked advice's `conclusion`, the besluit object SHALL carry a non-empty `motivatie_afwijking_advies` field explaining the reason for the deviation. The check SHALL run at the besluit-finalisation step in the parent `bezwaar-lifecycle` capability. If `motivatie_afwijking_advies` is missing or empty, the besluit SHALL NOT be marked final.

**Feature tier**: V1
**Awb reference**: Art. 7:13(7) (deviation rule rooted in general motivation principle Art. 3:46–3:50)

#### Scenario: Deviation without motivation is blocked

- **GIVEN** an advice with `conclusion = gegrond` linked to bezwaar Z1
- **AND** a draft besluit op bezwaar with outcome `ongegrond` and empty `motivatie_afwijking_advies`
- **WHEN** the bezwaar handler tries to finalise the besluit
- **THEN** the finalisation SHALL be rejected with HTTP 422
- **AND** the response SHALL state `Motivatie voor afwijken van het advies is verplicht (Awb Art. 7:13)`
- **AND** the besluit SHALL remain in draft

#### Scenario: Conforming besluit needs no motivation

- **GIVEN** an advice with `conclusion = ongegrond`
- **AND** a draft besluit with outcome `ongegrond`
- **WHEN** the bezwaar handler finalises the besluit
- **THEN** the besluit SHALL be accepted without requiring `motivatie_afwijking_advies`

### Requirement: Advice Publication With Besluit op Bezwaar

When the parent bezwaar-lifecycle publishes the besluit op bezwaar (bekendmaking step), the linked advice document SHALL be included in the publication packet so that interested parties can consult both the besluit and the advice that informed it. The advice SHALL be marked with the same retention class as the besluit and SHALL inherit the besluit's confidentiality classification.

**Feature tier**: V1
**Awb reference**: Art. 7:13(7) read with Art. 8 Wob/Woo (transparency)

#### Scenario: Publication packet includes the signed advice

- **GIVEN** a bezwaar case with an `advice-issued` advice request and a finalised besluit op bezwaar
- **WHEN** the parent bezwaar-lifecycle's bekendmaking step publishes the besluit
- **THEN** the publication packet SHALL include the advice PDF
- **AND** the advice's retention class SHALL match the besluit's retention class
- **AND** the advice's `vertrouwelijkheidsaanduiding` SHALL match that of the besluit

### Requirement: Advice Request Audit Trail

The system SHALL record every state change and every mutation of an advice request and its advice document in the OpenRegister automatic per-save audit log. In addition, the system SHALL append explicit, append-only entries to a `bac_audit_trail` field on the advice request for the following events: `panel-member-added`, `panel-member-removed`, `independence-check-failed`, `advice-signed-by-chair`, `council-deviation-recorded`. Each entry SHALL carry the actor UID, server timestamp, and a structured payload (e.g. failing member UID + reason for `independence-check-failed`).

**Feature tier**: V1
**Compliance basis**: Archiefwet 1995 (Dutch Public Records Act) — accountability for advisory bodies

#### Scenario: Chair signature is auditable

- **GIVEN** a chair signs the advice and the request transitions to `advice-issued`
- **WHEN** the transition completes
- **THEN** an `advice-signed-by-chair` entry SHALL be appended to `bac_audit_trail`
- **AND** the entry SHALL contain the chair's UID, the server timestamp, and the `signature_evidence` reference
- **AND** the OpenRegister automatic audit log SHALL also record the underlying object update

#### Scenario: Independence failure is auditable

- **GIVEN** a panel containing a member who signed the contested primair besluit
- **WHEN** the system blocks the transition to `in-deliberation` (per REQ-BAC-2)
- **THEN** an `independence-check-failed` entry SHALL be appended to `bac_audit_trail`
- **AND** the entry SHALL identify the failing member and the besluit reference

### Requirement: Advice Request Security and Authorization

The system SHALL derive the acting user identity exclusively from `IUserSession` for every mutating endpoint on the committee, advice request, and advice document. Read access to the advice request and the (unpublished) draft advice SHALL be limited to: the bezwaar handler of the parent case, the assigned committee's members and secretary, and any case-shared participants with explicit access. Write access SHALL be limited to: the bezwaar handler (for assignment and panel composition), the committee secretary (for draft advice content), and the committee chair (for the final signature transitioning the request to `advice-issued`). Once published with the besluit (REQ-BAC-6), the advice SHALL inherit the besluit's public/confidential visibility.

**Feature tier**: V1

#### Scenario: Non-member cannot read the draft advice

- **GIVEN** an advice request in `in-deliberation` for bezwaar Z1 with committee C1
- **AND** an authenticated user U2 who is neither the bezwaar handler nor a member of C1
- **WHEN** U2 calls `GET /api/bezwaar/advice-requests/{id}`
- **THEN** the system SHALL return HTTP 403
- **AND** no advice content SHALL be disclosed in the response body

#### Scenario: Only the chair can issue the advice

- **GIVEN** an advice request in `in-deliberation` with chair `j.smit` and member `a.bakker`
- **WHEN** member `a.bakker` calls the sign-and-issue endpoint
- **THEN** the system SHALL return HTTP 403 with `{"message": "Alleen de voorzitter kan het advies vaststellen"}`
- **AND** no transition SHALL occur and the request SHALL remain in `in-deliberation`
