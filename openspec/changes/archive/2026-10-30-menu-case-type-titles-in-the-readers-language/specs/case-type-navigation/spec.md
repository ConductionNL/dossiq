# case-type-navigation delta: menu-case-type-titles-in-the-readers-language

## ADDED Requirements

### Requirement: REQ-CTN-007 — A case type in my menu is named in the reader's language

The case types offered for "My case types", the chosen list and the menu entries
SHALL carry the case type's title as text in the reader's language. Because the
title is translatable and stored as a language map, the title SHALL be resolved to
the reader's language, then to Dutch, then to the first text the map holds. A case
type with no title SHALL be named by its id. A title SHALL never be shown as
"Array".

#### Scenario: A translatable title is read in the reader's language
@e2e exclude the language map is resolved server side and the instance seeds Dutch titles only; proven by tests/Unit/Service/MenuCaseTypesServiceTest.php::testATranslatableTitleIsReadInTheReadersLanguage

- **GIVEN** a case type whose title is `{"nl": "Kapvergunning", "en": "Tree felling permit"}`
- **WHEN** a reader whose language is English opens the case type picker
- **THEN** the case type SHALL be named "Tree felling permit"

#### Scenario: A title without the reader's language falls back to Dutch
@e2e exclude the language map is resolved server side; proven by tests/Unit/Service/MenuCaseTypesServiceTest.php::testATranslatableTitleIsReadInTheReadersLanguage

- **GIVEN** a case type whose title is `{"nl": "Woo-verzoek"}`
- **WHEN** a reader whose language is English opens the case type picker
- **THEN** the case type SHALL be named "Woo-verzoek"
