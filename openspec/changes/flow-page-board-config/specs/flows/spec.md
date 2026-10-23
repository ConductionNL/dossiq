## ADDED Requirements

### Requirement: The case type flow page follows board DqFlow

The flow page MUST curate its palette to the steps a case worker uses, draw each step as a card with its app pill, save
edits to a published flow as the next version that new cases use, and the Zuiddrecht example set MUST ship a case type
flow that shows this.

#### Scenario: The palette
- WHEN a user opens a flow in dossiq
- THEN the palette shows Dossiq, OpenRegister, Integriq and Filinq with their steps in Dutch, and "Meer stappen"

#### Scenario: Editing the published case type flow
- GIVEN the published flow "Omgevingsvergunning regulier" at version 4
- THEN the path reads "versie 4 · wijzigingen gelden voor nieuwe zaken" and Save publishes the edits as version 5

#### Scenario: The example flow
- GIVEN the Zuiddrecht example set is installed
- THEN the flow "Omgevingsvergunning regulier" exists with its eight steps placed as on the board, switched off
