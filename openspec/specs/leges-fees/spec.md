---
retrofit: true
---

# Leges Fees Specification

## Purpose

@e2e exclude Leges fee calculation is V1; calculation engine and export are backend services not testable via Playwright.

Calculate municipal fees (leges) on permit cases by applying the gemeentelijke legesverordening to case attributes, support recalculation with audit-traceable correction reasons, derive verrekening (offset) and teruggaaf (refund) figures, and export the resulting berekeningen to legacy financial systems in CSV / ASCII / StUF-FIN XML.

## Requirements

### REQ-001: Leges calculate API endpoint with JSON validation and try/catch wrapping

The system SHALL expose a `calculate` JSON endpoint on `LegesController` that accepts `caseData` and `verordening` (object or JSON string), resolves the calling user (or `'system'`), delegates to `LegesCalculationService::calculate`, and wraps any failure in a JSON error response.

#### Scenario: Required parameters

- WHEN `calculate` is called with empty `caseData` or `verordening`
- THEN the endpoint SHALL return HTTP 400 `{error: 'Parameters caseData and verordening are required'}`

#### Scenario: JSON-string parameter normalisation

- WHEN `caseData` or `verordening` arrive as JSON strings rather than parsed objects
- THEN the controller SHALL `json_decode(..., true)` them in place (falling back to `[]`) before delegation

#### Scenario: Failure wrapping

- WHEN the calculation service throws any `\Throwable`
- THEN the controller SHALL log `'Leges calculation failed: ' . $e->getMessage()` and return HTTP 500 `{error: 'Calculation failed: <message>'}`

#### Notes

- User resolution uses `IUserSession::getUser()?->getUID() ?? 'system'` — anonymous calls record `'system'` as the calculator.

### REQ-002: Leges calculation rules engine with five calculation types

The system SHALL provide a `LegesCalculationService::calculate` method that walks the verordening's artikelen, dispatches each artikel to its calculation type (`vast` / `percentage` / `staffel` / `maximum` / `combinatie`), accumulates a typed breakdown (artikel, description, grondslag, amount, type), and returns `{total, breakdown[], verordening, calculatedBy, calculatedAt, version}` with totals rounded to 2 decimal places.

#### Scenario: Supported calculation types

- WHEN an artikel declares `type` of `vast`, `percentage`, `staffel`, `maximum`, or `combinatie`
- THEN the engine SHALL route to the matching internal calculator and append the result to `breakdown`

#### Scenario: Precision and totalling

- WHEN the engine sums artikel amounts into `total`
- THEN every monetary value SHALL be rounded to `PRECISION = 2` decimal places

#### Scenario: Audit fields on the result

- WHEN `calculate` returns
- THEN the result SHALL include `calculatedBy` (caller-supplied user id), `calculatedAt` (timestamp string), `verordening` (identifier), and `version` (integer)

#### Notes

- The five calculation type constants are `public const` so callers and tests can pin the contract: `TYPE_VAST`, `TYPE_PERCENTAGE`, `TYPE_STAFFEL`, `TYPE_MAXIMUM`, `TYPE_COMBINATIE`.

### REQ-003: Recalculation with previous-calculation context and correction reason

The system SHALL provide a `recalculate` endpoint and service method that re-runs the calculation with the same shape as `calculate` plus a `previousCalculation` snapshot and a `correctionReason` string, returning a new versioned berekening that audit-trails the override.

#### Scenario: Recalculate parameters

- WHEN `recalculate` is called with `caseData`, `verordening`, `previousCalculation`, and `correctionReason`
- THEN every JSON-string parameter SHALL be decoded to an array before delegation
- AND the result SHALL be passed through the same JSON envelope + try/catch handling as REQ-001

#### Scenario: Empty correction reason allowed

- WHEN no `correctionReason` is supplied
- THEN the endpoint SHALL still delegate with an empty string and the service SHALL accept it

#### Notes

- The correction reason is the human-readable audit narrative; storage-level enforcement of "non-empty for corrections" is observed-but-not-required at this layer.

### REQ-004: Verrekening (deduction) and Teruggaaf (refund) calculation helpers

The system SHALL expose `verrekening` (current amount minus previously-imposed amount) and `teruggaaf` (refund = imposed amount × refund fraction with reason) JSON endpoints that delegate to dedicated service methods and wrap failures in HTTP 500 JSON envelopes.

#### Scenario: Verrekening math

- WHEN `verrekening` is called with `currentAmount` and `previousAmount`
- THEN the service SHALL return the structured deduction result for the difference

#### Scenario: Teruggaaf math

- WHEN `teruggaaf` is called with `imposedAmount`, `refundFraction` (default `1.0`), and `reason`
- THEN the service SHALL return the structured refund result for `imposedAmount * refundFraction` with the reason carried through

#### Notes

- All amounts are coerced to `float` at the controller boundary.

### REQ-005: Export to financial systems in CSV / ASCII / XML (StUF-FIN)

The system SHALL provide a `LegesExportService::export` method that emits berekeningen in one of three formats — `csv`, `ascii`, `xml` (StUF-FIN compatible) — returning `{content, filename, contentType}` for the controller to wrap in a `DataDownloadResponse`. CSV exports SHALL use the column order `zaaknummer, bsn_kvk, naam, adres, artikelnummer, omschrijving, bedrag, datum_beschikking`.

#### Scenario: Format validation

- WHEN `export` is called with a format outside `SUPPORTED_FORMATS`
- THEN it SHALL throw `\InvalidArgumentException` and the controller SHALL return HTTP 400 with the exception message

#### Scenario: Empty berekeningen rejected

- WHEN the `export` endpoint is called with empty `berekeningen`
- THEN it SHALL return HTTP 400 `{error: 'No berekeningen provided for export'}` before invoking the service

#### Scenario: Download wrapping

- WHEN export succeeds
- THEN the controller SHALL return a `DataDownloadResponse` with the service's `content`, `filename`, and `contentType`

#### Notes

- The CSV header order is fixed to keep downstream financial-system parsers stable.
