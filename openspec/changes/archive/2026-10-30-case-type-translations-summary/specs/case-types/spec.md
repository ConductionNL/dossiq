# case-types Delta

## ADDED Requirements

### Requirement: The Overview tab summarises the translations

The case type Overview tab SHALL show one row per language the register declares, with the number of its labels that are
translated and not out of date, and a pill that says the language is complete or how many labels it misses. Edit SHALL
open the Translations tab, which keeps the editor.

#### Scenario: DqZaaktypeOverzicht

- **GIVEN** a case type with two translatable labels, Dutch the source and one current English label
- **THEN** the Overview tab reads "Nederlands … Compleet" and "Engels 1 van 2 labels … 1 ontbreken"
