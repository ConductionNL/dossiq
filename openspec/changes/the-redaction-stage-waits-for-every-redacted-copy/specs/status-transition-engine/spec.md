# status-transition-engine Delta: the-redaction-stage-waits-for-every-redacted-copy

**Status**: draft
**Scope**: the `documentAssessment` guard kind, and the Woo request flow's move into the decision stage.

## ADDED Requirements

### Requirement: A move MAY require every partly public document redacted

The guard kind `documentAssessment` SHALL accept `require: redacted`. It SHALL pass only when every document of the case assessed as partly public has a redacted copy (`redactedDocumentRef`), and SHALL pass when the case has none. It SHALL read the assessments regardless of the caller. When they cannot be read, it SHALL fail with a sentence saying so. The Woo request flow SHALL require it, beside `assessed`, on the move from Lakken / Anonimiseren to Besluit, and the `woo-verzoek` package version SHALL rise so existing installs receive the flow.

@e2e exclude The redacted copy needs filinq or the redaction upload route, which the e2e Woo journey does not exercise yet. Covered by DocumentAssessmentGuardRedactedTest and WooFlowGuardsTest through the real engine.

#### Scenario: One copy is missing

- **GIVEN** a Woo request in Lakken / Anonimiseren with two partly public documents, one with a redacted copy
- **WHEN** the handler opens the moves out of its status
- **THEN** "Besluit voorbereiden" is disabled with "1 van 2 deels openbare documenten wachten nog op een gelakte versie"

#### Scenario: Every copy is there

- **GIVEN** the same case after the second redacted copy is recorded
- **THEN** "Besluit voorbereiden" is offered

#### Scenario: An existing install receives the guard

- **GIVEN** an instance that installed `woo-verzoek` 1.0.0 and never edited its flow
- **WHEN** it upgrades to a dossiq shipping 1.1.0
- **THEN** its Woo request flow carries the `redacted` requirement
