## ADDED Requirements

### Requirement: The case assistant stands in a card that says what it sees

On the AI oversight tab the side column SHALL show the assistant in a card titled Assistant, with the assistant's name as a pill, a line saying it sees only what the reader sees on the case, and an input asking about the case.

#### Scenario: a handler opens the AI oversight tab
@e2e exclude config pinned by tests/vitest/assistantCardBoard.spec.js; the card is drawn by nextcloud-vue (CnAiChatWidgetEmbedded.spec.js)

- **GIVEN** hermiq is installed and answers
- **WHEN** a handler opens the AI oversight tab of a case
- **THEN** the side column SHALL show "Assistent" with a "Hermiq" pill
- **AND** the line "De assistent ziet alleen wat u op deze zaak ziet. Is Hermiq er niet, dan staat dit paneel uit."
- **AND** an input "Vraag iets over deze zaak" with a "Vragen" button
