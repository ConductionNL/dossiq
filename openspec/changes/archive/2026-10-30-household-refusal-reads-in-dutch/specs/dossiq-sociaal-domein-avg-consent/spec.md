## ADDED Requirements

### Requirement: A household the reader may not see says so in their language

When the household endpoint refuses a reader, the household card SHALL show
the refusal's sentence in the reader's language, not a load error.

#### Scenario: Pieter outside the sensitive group
- GIVEN a social domain case and a reader outside `dossiq-sensitive`
- WHEN the Overview shows "Andere zaken van dit huishouden"
- THEN it reads "Alleen wie met de bijzondere persoonsgegevens van deze zaak werkt, ziet het huishouden."
