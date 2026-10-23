## ADDED Requirements

### Requirement: The case type flow page draws board DqZaaktypeFlow

The case type flow page MUST name the case type and the version under its title, draw each status as a card with its
colour as a dot, and draw each transition between the nodes it joins.

#### Scenario: Lines meet their nodes
- GIVEN statuses at (100, 40) and (340, 40)
- THEN the line between them runs from (200, 58) to (440, 58) in the canvas, between the title rows

#### Scenario: The subtitle
- GIVEN the case type "Omgevingsvergunning regulier" with draft version 5 on screen
- THEN the subtitle reads "Omgevingsvergunning regulier · versie 5 concept"
