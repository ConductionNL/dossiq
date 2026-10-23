# admin-settings Delta

## ADDED Requirements

### Requirement: The Systeem cards read as the board

The Systeem tab SHALL describe each card in one plain line and label its fields as board DqBeheerinstellingen does. A
field the board does not draw SHALL stay available behind the card's Geavanceerd disclosure, never be removed.

#### Scenario: Zaak-e-mail

- **GIVEN** the Systeem tab
- **THEN** the card reads "Mail aan de gedeelde mailbox komt binnen als zaak of bij een bestaande zaak."
- **AND** its fields are Mailaccount voor intake, Map, Ophalen elke (seconden), Berichten per keer, Zaaktype voor mail die niemand oppakt and "Mail op zaaknummer aan zaken koppelen", the rest under Geavanceerd
