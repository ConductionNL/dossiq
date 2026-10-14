# frontend-locale-completeness Delta: refusals-answer-in-the-readers-language

**Status**: draft
**Scope**: dossiq controllers that answer a `RefusedException` through `TranslatesRefusals`.

## ADDED Requirements

### Requirement: A refusal MUST reach the reader in their language

A refusal's `message` SHALL be its sentence translated through the reader's translator. A sentence the catalogue does not hold SHALL go out as written, and SHALL never be empty because translation failed. The `error` and `code` keys SHALL keep their slugs.

#### Scenario: Closing without a result answers in Dutch

- **GIVEN** a handler whose language is Dutch
- **WHEN** they close a case whose case type has result types without picking one
- **THEN** the response is 422 with `message` "Kies een resultaat voordat je deze zaak afsluit."
- **AND** `error` is `result-type-required` and `code` is `result_type_required`

#### Scenario: No translator keeps the sentence

- **GIVEN** a request with no translator available
- **WHEN** the same refusal is answered
- **THEN** `message` is "Pick a result before closing this case."
