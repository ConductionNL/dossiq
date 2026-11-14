# case-types Delta

## ADDED Requirements

### Requirement: The Overview's Regels card lists the case type's own rules

The Overview tab's Regels card SHALL list the case type's own rules as the Rules tab does ("Als … / dan …" with an
Aan or Uit pill), without row menus, an Add button, the schema inventory or the trial, and SHALL say "Nog geen regels
voor zaken" when there are none. "Regel toevoegen" in its header SHALL open the Rules tab.

#### Scenario: Omgevingsvergunning regulier with three rules

- **THEN** the card reads "Als Bouwkosten groter is dan € 100.000 / dan taak Toets door constructeur toevoegen" with
  Aan, and two more rows
