# Design: woo-redaction-reads-filinqs-detector-report

Read at dossiq development `e5cc70afb` and filinq `fix/anonymisation-fails-closed` `44d5fc0c`.

## Context

- filinq `AnonymizationService::anonymizeDocument()` runs `runAnonymize()`,
  which calls `requireLiveDetector()` and throws
  `OCA\Filinq\Exception\DetectionUnavailableException` (`getReason()`:
  `detection_disabled`, `detection_backend_unavailable`,
  `detection_state_unknown`; `getBackend()`, '' when unknown). A completed run
  carries `detection: {ran, backend, entitiesRedacted, outcome}`, `outcome`
  `redacted` or `nothing_found`.
- dossiq resolves filinq's service through `FleetAppId` and never names a
  filinq class.

## D1. Recognise the refusal by name

The short class name plus `getReason()` and `getBackend()`, because filinq is
optional and its namespace moves with its app id. A test stub is a verbatim
copy of the real class (`tests/Stubs/Filinq/Exception/`).

## D2. Still fail closed

`detection_unavailable` is not `redacted`, so `WOORedactionService` puts the
document on the manual list, with its own reason.
