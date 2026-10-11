# Decision document

## Purpose

A case often ends in a decision sent to someone: a grant, a refusal, an order. Every such decision
is drafted from a template, checked, decided by someone with the authority to decide it, signed,
numbered, sent and kept unchanged, and tells its addressee how to object. This capability does
that for any kind of decision. What a kind needs is configuration on the case type, under
`decisionDocuments.<kind>`; the code knows no procedure (capability C03, decisions 182 and 185).

## Requirements

### Requirement: A case type configures each kind of decision it issues (REQ-DDC-001)

A case type SHALL be able to configure, per kind of decision, under `decisionDocuments.<kind>`: the
template, the required fields, validity rules (each an id, an OpenRegister condition `when` that may
reference the kind's named `conditions` by `$condition`, and a message), the remedy clause, whether
a remedy clause is required (default yes), whether the decision is signed (default yes), the
delegated-authority act that decides it, the decidiq decision kind, the number series (name and
default prefix) and the statuses from which it is frozen (default signed, sent and archived). A
kind that requires a remedy clause and configures none, a rule without an id or condition, or a
frozen status that is not a decision status SHALL be refused when it is read. `decisionTypes`
SHALL keep holding the ZGW besluittype references unchanged.

#### Scenario: A remedy clause is not optional by accident

- **GIVEN** a case type whose kind `notice` configures no remedy clause and does not switch it off
- **WHEN** the kind is read
- **THEN** it SHALL be refused as incomplete

### Requirement: A draft starts from the kind's configuration (REQ-DDC-002)

A new draft SHALL carry the kind, the case, status `draft`, and the kind's template, remedy clause
and decision kind; fields the drafter supplies SHALL win over those defaults, except the kind, the
case and the status.

### Requirement: Only a valid decision is approved, by someone who may decide it (REQ-DDC-003)

Approval SHALL be refused with HTTP 422 and the rule `decision-document-invalid`, listing every
problem, when a required field is empty, when a required remedy clause is empty, or when a rule does
not hold. A rule that cannot be evaluated SHALL count as not holding and say so. When the kind
names an authority act, the delegated-authority check SHALL decide whether the actor may approve,
and its refusal SHALL stand; when no authority check is available, approval SHALL be refused with
HTTP 503 and the rule `decision-authority-unavailable`.

#### Scenario: An unevaluable rule does not pass

- **GIVEN** a kind with a rule whose condition cannot be evaluated
- **WHEN** a decision of that kind is approved
- **THEN** approval SHALL be refused, naming that rule as not checked

### Requirement: Signing needs the signature and numbers the decision (REQ-DDC-004)

A decision SHALL move only along draft to approved, approved back to draft or on to signed, signed
to sent, and sent to archived; any other move SHALL be refused with HTTP 409 and the rule
`decision-document-transition`. Marking a signed kind as signed SHALL be refused with HTTP 422 and
the rule `decision-document-unsigned` while the decision carries no signature. On signing, a
decision of a kind with a series and without a number SHALL take the series' next number
(REQ-NDS-001); a decision that already has one SHALL keep it.

### Requirement: A signed decision is a frozen record (REQ-DDC-005)

Each kind SHALL yield a frozen-record entry (REQ-FRZ-001) for the schema its decisions are stored
in: frozen from the kind's frozen statuses, freezing its content fields and required fields,
sealing those and its number, writing the signature and the moment of sending once, numbering a
correction in the kind's series, and refusing with the rule `decision-document-frozen`.

#### Scenario: The signed decision does not change

- **GIVEN** a signed decision of the fixture kind
- **WHEN** a write changes its decision text
- **THEN** the write SHALL be refused with HTTP 409 and the rule `decision-document-frozen`
- **AND** moving it to `sent` SHALL still be allowed
