# mandaat-matrix Specification — Member 07: Admin UI

---
status: proposed
---

## Purpose

Provide an admin panel to manage mandaten, the OrganisatieRol hierarchy, and person-to-role
assignments, plus access to the decidesk import workflow.

## ADDED Requirements

### Requirement: Mandate Matrix Admin Panel

The system SHALL provide an admin settings page with tabs for Besluiten, Rollen, Toewijzingen, and
Import, allowing admins to view and edit mandaten per mandateringsbesluit.

#### Scenario: Admin edits a mandate

- GIVEN an admin opens Settings > Mandate Matrix > Besluiten
- WHEN they select an active mandateringsbesluit and click Edit on a Mandaat
- THEN the MandaatEditor SHALL show fields mandaatNummer, omschrijving, bevoegdheidType,
  wettelijkeGrondslag, a voorwaarden editor (plafond_bedrag, subdelegatie_toegestaan), validity
  date pickers, and a role selector
- AND saving SHALL persist the change via the backend mandate endpoint

### Requirement: OrganisatieRol and Toewijzing Management

The admin panel SHALL allow managing the role hierarchy and person-to-role assignments, including
waarnemer assignments, with referential-integrity guards.

#### Scenario: Role deletion blocked when referenced

- GIVEN an OrganisatieRol referenced by a Mandaat or an active MedewerkerRolToewijzing
- WHEN an admin attempts to delete it
- THEN the system SHALL block the deletion with an error

#### Scenario: Waarnemer assignment created

- GIVEN the Toewijzingen tab is open
- WHEN an admin adds an assignment selecting a person, role, start date, and type "waarnemer"
- THEN a MedewerkerRolToewijzing SHALL be created with `toewijzingType: "waarnemer"`
- AND the assignment SHALL be visually distinguished from primair assignments in the table

#### Scenario: Ending an assignment

- GIVEN an active MedewerkerRolToewijzing
- WHEN the admin clicks "End assignment" and confirms a date
- THEN `toewijzingTotEnMet` SHALL be set to that date
