# libresign-besluit-signing (delta)

## REMOVED Requirements

### Requirement: REQ-LBS-001 — Signature Request Creation

**Reason**: Dossiq no longer calls LibreSign. Filinq creates the signature request.

**Migration**: Replaced by "A document is signed through filinq".

### Requirement: REQ-LBS-002 — Signer Resolution From Mandaat Data

**Reason**: Filinq resolves the signer from the user id dossiq sends.

**Migration**: Replaced by "A document is signed through filinq".

### Requirement: REQ-LBS-003 — Status Polling And Mapping

**Reason**: Dossiq no longer polls. Filinq concludes the request and dossiq hears it.

**Migration**: Replaced by "A signing request that ends unsigned is shown as not concluded".

### Requirement: REQ-LBS-004 — Signed-File Storage Into The Zaakdossier

**Reason**: Filinq stores the signed document and names it on the conclusion; the decision points
at it.

**Migration**: The decision's `compositeContent.fileId` takes filinq's signed document reference.

### Requirement: REQ-LBS-005 — LibreSign-Unavailable Fallback

**Reason**: The fallback wrote a made-up signature. Without filinq a decision is not signed, and
the answer says so.

**Migration**: Replaced by "A document is signed through filinq".

## ADDED Requirements

### Requirement: A document is signed through filinq

Asking for a decision's signature SHALL raise filinq's `DocumentSigningRequestedEvent` with source
app `dossiq`, the decision as subject, its file as document, the signer's Nextcloud user id and
the signature level configured in `signing.level` (SES, AdES or QES; SES otherwise). It SHALL
answer HTTP 202 and leave the decision's status unchanged, with `signing.state = requested` and
filinq's request id. While a request is open a second one SHALL be refused (409). When filinq is
not installed or does not take the request, the answer SHALL be 503 and nothing SHALL be written.

#### Scenario: Filinq takes the request

- **GIVEN** an approved decision and filinq installed
- **WHEN** its signature is asked for
- **THEN** filinq's request event SHALL carry the decision id, the signer and the configured level
- **AND** the decision SHALL keep its status and carry `signing.state = requested`

#### Scenario: Filinq is absent

- **GIVEN** filinq does not take the request
- **WHEN** the signature is asked for
- **THEN** the answer SHALL be `signing_unavailable` (503) and the decision SHALL be unchanged

### Requirement: A signing request that ends unsigned is shown as not concluded

When filinq concludes a request dossiq raised, a `signed` conclusion SHALL move the decision to
signed, with the signature level, filinq's request id and the signed document. A `declined`,
`cancelled` or `expired` conclusion SHALL set `signing.state = not-concluded` with filinq's status
as `signing.outcome`, leave the decision's status unchanged and allow a new request. A conclusion
for a request the decision is not waiting on SHALL change nothing.

#### Scenario: Filinq's request expires

- **GIVEN** a decision waiting on a signing request
- **WHEN** filinq concludes it `expired` after `signing_request_expiry_days`
- **THEN** the decision SHALL show `signing.state = not-concluded` and `signing.outcome = expired`
- **AND** its signature MAY be asked for again

#### Scenario: Filinq's request is signed

- **GIVEN** a decision waiting on a signing request
- **WHEN** filinq concludes it `signed`
- **THEN** the decision SHALL be signed and its file SHALL be filinq's signed document

### Requirement: The audit packet says when no validation report is available

The audit packet of a signed decision SHALL contain a `validatierapport.json` that says no
validation report is available and names filinq's signing request, rather than a report nobody
produced.

#### Scenario: A decision signed through filinq is exported

- **GIVEN** a decision signed through filinq
- **WHEN** its audit packet is exported
- **THEN** `validatierapport.json` SHALL hold `available: false` and the signing request id
