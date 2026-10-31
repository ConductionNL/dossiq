# admin-settings Delta: admin-advanced-tab

## ADDED Requirements

### Requirement: The specialist settings sit on their own tab

The admin page SHALL end its tab strip with "Geavanceerd" (`advanced`), holding the specialist sections listed in the proposal. The System tab SHALL hold Configuratie, Menustructuur, Serviceaccount, Zaak-e-mail, AI-ondersteunde verwerking, Adviseringsbeheer and Zoekindex; the Integrations tab Koppelingen and the Kadaster card. A connection row whose settings card moved SHALL link to that card on the Geavanceerd tab.

#### Scenario: StUF-ZKN from its connection row
- GIVEN the Integraties tab
- WHEN the administrator opens the StUF-ZKN row
- THEN the StUF-ZKN endpoints card opens on the Geavanceerd tab

### Requirement: The decision tables card reads its text as body

The "Decision tables (DMN)" card on the Geavanceerd tab SHALL carry its text as the card's body (15px at 1.5, main
ink, under the title), not as the card description.

#### Scenario: DqBeheerGeavanceerd

- **GIVEN** the Geavanceerd tab in the board look
- **THEN** "Beslistabellen staan in het register decision-tables" reads 15px in the main ink under "Beslistabellen (DMN)"
