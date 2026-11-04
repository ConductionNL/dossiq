# example-set-zuiddrecht-data Specification

## Purpose
What the Zuiddrecht example set holds so the work boards and the installer agree with it. Each requirement is checked by a PHPUnit test over the shipped set in `lib/Settings`.

@e2e exclude Example data in the shipped set; each requirement is covered by its PHPUnit test under tests/Unit/Settings.

## Requirements

### Requirement: Pieter has one archival review waiting

The Zuiddrecht example set SHALL hold one open destruction list in OpenRegister's destruction-list register. Its entry for "Melding kapot speeltoestel Oosthavenplein" SHALL wait for Pieter Jansen.

#### Scenario: Pieter opens his archival reviews
- **GIVEN** the set is installed
- **WHEN** Pieter opens "Mijn archiefbeoordelingen"
- **THEN** one review SHALL wait for him, for "Melding kapot speeltoestel Oosthavenplein"

### Requirement: Open Woo requests sit in the Woo team

Every open Woo request in the Zuiddrecht example set SHALL be assigned to the team "Woo en informatie".

#### Scenario: The Woo team opens its queue
- **GIVEN** the set is installed
- **WHEN** a member of "Woo en informatie" opens the team queue
- **THEN** every open Woo request of the set SHALL be in it

### Requirement: A change to a case keeps its geometry as JSON text

A change in the Zuiddrecht example set that targets a case with a geometry SHALL name that field in `jsonFields`. The installer validates the change as the case would be written, and OpenRegister reads a geometry kept as JSON text back as a structure.

#### Scenario: The set installs a change to a case with a geometry
- **GIVEN** a change to a case whose schema keeps `geometry` as JSON text
- **WHEN** the set is installed
- **THEN** the change SHALL list `geometry` in `jsonFields`, and the installer SHALL accept it

### Requirement: Mail template placeholders are kept verbatim

A Zuiddrecht mail template SHALL name the fields that hold its own placeholders (such as `{{zaakNummer}}`) in `verbatimFields`. The installer fills `{{kind:...}}` tokens in object data, so an unnamed placeholder field gets the set refused.

#### Scenario: The set installs its mail templates
- **GIVEN** a mail template whose body holds `{{zaakNummer}}`
- **WHEN** the set is installed
- **THEN** the body SHALL keep `{{zaakNummer}}` as written
