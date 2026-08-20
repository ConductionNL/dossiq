# libresign-besluit-signing Specification

**Status:** proposed
**Scope:** procest

## Purpose

Provide a concrete, LibreSign-backed implementation of the beschikking pipeline's
`SigningAdapterInterface` so beschikkingen (decisions/rulings) can be digitally signed inside the
platform, with signer identity resolved from mandaat-authorised actors, LibreSign's status
vocabulary mapped onto procest's own pending/signed/declined outcomes, signed files persisted
through the existing zaakdossier storage path, and a clean, non-breaking fallback when LibreSign
is not installed.

## ADDED Requirements

### Requirement: REQ-LBS-001 — Signature Request Creation

When `onderteken()` is invoked on a beschikking and LibreSign is enabled, `LibresignSigningAdapter::sign()` SHALL create a LibreSign signature request for the beschikking's PDF, identified by its Nextcloud file id (`$bestandId`), via `LibresignApiClient::requestSignature()` — the single thin HTTP boundary for all outbound LibreSign calls.

#### Scenario: A signature request is created with the correct file id and signer

- **GIVEN** a beschikking with `samengesteldeInhoud.bestandId` set to a valid Nextcloud file id
  and an `$ondertekenaar` UID that resolves to an NC user with a configured email
- **WHEN** `LibresignSigningAdapter::sign($bestandId, $ondertekenaar, $tspProvider)` is called
- **THEN** `LibresignApiClient::requestSignature()` SHALL be called with that exact file id and a
  `users` list containing exactly one signer built from the resolved NC user's email and display
  name
- **AND** no other outbound HTTP call SHALL be made outside `LibresignApiClient`

### Requirement: REQ-LBS-002 — Signer Resolution From Mandaat Data

`LibresignSigningAdapter` SHALL resolve the LibreSign signer identity from the `$ondertekenaar` UID supplied by `BeschikkingService::onderteken()` — the mandaat-authorised actor recorded by the prior `akkoord()` step — via `IUserManager`, and WHEN the UID does not resolve to an account, or resolves to an account with no configured email address, `sign()` SHALL throw `RuntimeException('libresign_signer_unresolvable')` before any LibreSign API call is made.

#### Scenario: Signer resolves to a real NC account with an email

- **GIVEN** `$ondertekenaar` is the UID of an NC user with display name "J. Jansen" and email
  `j.jansen@example.nl`
- **WHEN** `sign()` builds the LibreSign request
- **THEN** the signer entry SHALL carry `identify.email = 'j.jansen@example.nl'` and
  `displayName = 'J. Jansen'`

#### Scenario: Mandaat data is missing or incomplete

- **GIVEN** `$ondertekenaar` is a UID that either does not resolve to any NC user, or resolves to
  a user with no email address configured
- **WHEN** `sign()` is called
- **THEN** it SHALL throw `RuntimeException('libresign_signer_unresolvable')`
- **AND** `LibresignApiClient::requestSignature()` SHALL NOT be called
- **AND** `BeschikkingController::mapRuntime()` SHALL map this to HTTP 422

### Requirement: REQ-LBS-003 — Status Polling And Mapping

After creating a signature request, `sign()` SHALL poll `LibresignApiClient::getStatus()` up to a configurable number of attempts, mapping each raw LibreSign status via `LibresignStatusMapper::map()` onto one of `PENDING`/`SIGNED`/`DECLINED`/`UNKNOWN`, and an `UNKNOWN` status SHALL be treated as `PENDING` (never as an implicit sign) and logged at `warning`.

#### Scenario: LibreSign status maps to the internal vocabulary

- **GIVEN** LibreSign's raw `statusText` values `draft`, `able_to_sign`, `partial_signed`,
  `signed`, `deleted`
- **WHEN** each is passed to `LibresignStatusMapper::map()`
- **THEN** the first three SHALL map to `PENDING`, `signed` SHALL map to `SIGNED`, and `deleted`
  SHALL map to `DECLINED`

#### Scenario: An unrecognised status value maps to UNKNOWN and is treated as pending

- **GIVEN** LibreSign returns a `statusText` of `"something-new"` that the mapper does not
  recognise
- **WHEN** `LibresignStatusMapper::map('something-new')` is called
- **THEN** it SHALL return `UNKNOWN`
- **AND** `LibresignSigningAdapter::sign()` SHALL continue polling (or exit the poll window)
  exactly as it would for `PENDING`, never proceeding as if the beschikking were signed

#### Scenario: The request is signed within the poll window

- **GIVEN** `LibresignApiClient::getStatus()` returns a status that maps to `SIGNED` within the
  configured poll attempts
- **WHEN** `sign()` observes this
- **THEN** it SHALL proceed to download and store the signed file and return the full `sign()`
  contract (`signedBestandId`, `validatieRapportId`, `certificaatSerienummer`,
  `tspProviderEidasId`, `ondertekeningTijdstip`)

#### Scenario: The request is declined

- **GIVEN** `LibresignApiClient::getStatus()` returns a status that maps to `DECLINED`
- **WHEN** `sign()` observes this
- **THEN** it SHALL throw `RuntimeException('libresign_signing_declined')`
- **AND** `BeschikkingController::mapRuntime()` SHALL map this to HTTP 409
- **AND** the beschikking SHALL remain in its current status (no transition to `ondertekend`)

#### Scenario: The request is still pending after the poll window

- **GIVEN** `LibresignApiClient::getStatus()` never reports `SIGNED` or `DECLINED` within the
  configured poll attempts
- **WHEN** the poll window is exhausted
- **THEN** `sign()` SHALL throw `RuntimeException('libresign_signing_pending')`
- **AND** `BeschikkingController::mapRuntime()` SHALL map this to HTTP 202
- **AND** the beschikking SHALL remain in its current status, signable again on a later
  `onderteken()` retry

### Requirement: REQ-LBS-004 — Signed-File Storage Into The Zaakdossier

When a signature request reaches `SIGNED`, `LibresignSigningAdapter` SHALL fetch the signed file's bytes by its Nextcloud file id and persist them through the existing `ZgwDocumentService::storeRaw()` binary storage service — the same service the rest of the zaakdossier already uses — rather than introducing a new storage mechanism.

#### Scenario: Signed bytes are stored via the existing document storage service

- **GIVEN** LibreSign reports `SIGNED` with `file.signedFileId` pointing at a readable Nextcloud
  file
- **WHEN** `sign()` processes the signed result
- **THEN** it SHALL call `ZgwDocumentService::storeRaw()` with the signed file's actual byte
  content and a filename derived from the original `$bestandId`
- **AND** the id returned by `storeRaw()` SHALL be returned as `signedBestandId`
- **AND** no alternative/new file-storage code path SHALL be introduced

### Requirement: REQ-LBS-005 — LibreSign-Unavailable Fallback

`SigningAdapterInterface` SHALL resolve to `LibresignSigningAdapter` only when `IAppManager::isEnabledForUser('libresign')` is true at DI-wiring time; otherwise it SHALL resolve to the pre-existing `MockSigningAdapter` unchanged, procest SHALL install, build, and run unchanged when LibreSign is absent, and a structured, translated admin-facing hint SHALL be logged and exposed via `SettingsController::index()`.

#### Scenario: LibreSign is not installed — pipeline falls back unchanged

- **GIVEN** the LibreSign app is not installed or not enabled
- **WHEN** procest resolves `SigningAdapterInterface` from the DI container
- **THEN** it SHALL receive the existing `MockSigningAdapter` instance
- **AND** a `warning`-level log entry SHALL be written carrying a translated hint that LibreSign
  is not installed/enabled and that installing it enables real digital signing
- **AND** `SettingsController::index()` SHALL report `libresignAvailable: false` and a non-null
  `libresignHint`

#### Scenario: LibreSign is toggled off mid-session (race)

- **GIVEN** `LibresignSigningAdapter` was resolved while LibreSign was enabled, but LibreSign is
  disabled by an admin before `sign()` is actually invoked
- **WHEN** `sign()` re-checks `IAppManager::isEnabledForUser('libresign')` at call time and finds
  it false
- **THEN** it SHALL throw `RuntimeException('libresign_unavailable')`
- **AND** `BeschikkingController::mapRuntime()` SHALL map this to HTTP 503

#### Scenario: LibreSign is installed and enabled

- **GIVEN** the LibreSign app is installed and enabled
- **WHEN** procest resolves `SigningAdapterInterface` from the DI container
- **THEN** it SHALL receive a `LibresignSigningAdapter` instance
- **AND** `SettingsController::index()` SHALL report `libresignAvailable: true`
