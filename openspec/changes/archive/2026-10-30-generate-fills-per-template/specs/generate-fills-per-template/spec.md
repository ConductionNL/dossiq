## ADDED Requirements

### Requirement: A template names what it fills in

The template library SHALL list each template's `fills`, an empty list when it names none.

#### Scenario: the adjournment letter
- THEN Verdagingsbrief lists the decision deadline and the adjournment

### Requirement: The generate dialog shows what the chosen template fills in

The generate dialog SHALL list, under the addressee and the case, each fill of the chosen template whose field the case has.

#### Scenario: Verdagingsbrief on a Woo case
- GIVEN a case with decision deadline 1 November 2026 and a statutory extension of P14D
- THEN the dialog lists "Beslistermijn 1 november 2026" and "Verdaging 2 weken, tot 15 november 2026"
