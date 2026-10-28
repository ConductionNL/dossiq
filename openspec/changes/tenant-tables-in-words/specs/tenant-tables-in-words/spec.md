## ADDED Requirements

### Requirement: a tenant's mandates and billing events read in words

The organisation page's Mandaten and Facturatie tables MUST show each stored code in words, as plain text and not as a pill.

#### Scenario: Gemeente Waterland mandates
- GIVEN the Teamleider mandate with decision types `vergunning` and `bezwaar` and enforcement `warning`
- THEN its row reads "Ja" under Bezwaar beslissen and "Waarschuwing" under Handhaving

#### Scenario: Gemeente Waterland billing
- GIVEN a billing event `case_refund`
- THEN its row reads "Terugboeking" as text

### Requirement: a list value is read as yes or no by a token it holds

The `containsLabel` formatter MUST return the translated `yes` word when the value (a list, or a comma separated text) holds `formatterOptions.token`, and the `no` word otherwise.

#### Scenario: no objections
- GIVEN decision types `vergunning`
- THEN the cell reads "Nee"

### Requirement: a tenant table without a row link reads its first column as text

The Mandaten and Facturatie lists open no record per row, so their first column MUST read in the body ink at regular weight (`rowTitle: "text"`), not in the link colour.

#### Scenario: Mandaten
- GIVEN the Overzicht tab of Gemeente Waterland
- THEN "Behandelaar" reads as plain text
