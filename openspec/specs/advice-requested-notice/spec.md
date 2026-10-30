# advice-requested-notice Specification

## Purpose
What dossiq does for advice requested notice. The requirements were first written by the change `advice-requested-notice` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-advice-requested-notice/`) and cover: A case shows that advice is still asked.

## Requirements

### Requirement: A case shows that advice is still asked

In the simple profile, the case page's side column SHALL open with a notice while at least one advice request with
status `requested` points at the case. The notice SHALL read "Advies gevraagd" and name the soonest due request's
advisor and answer date, and say that closing waits until then. Without an open request it SHALL not show.

#### Scenario: DqZaak "Advies gevraagd"

- **GIVEN** a case with an open advice request to Mobiliteit en verkeer, due 10 October
- **THEN** the side column opens with "Advies gevraagd aan Mobiliteit en verkeer. Antwoord uiterlijk 10 oktober.
  Afronden kan pas daarna."

#### Scenario: no open advice

- **GIVEN** a case whose advice requests are all received
- **THEN** the side column opens with the deadline card and shows no advice notice
