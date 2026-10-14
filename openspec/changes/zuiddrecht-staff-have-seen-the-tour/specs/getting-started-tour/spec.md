# getting-started-tour Delta: zuiddrecht-staff-have-seen-the-tour

**Status**: draft
**Scope**: the demo staff accounts of the Zuiddrecht example set.

## ADDED Requirements

### Requirement: The Zuiddrecht staff MUST have seen the getting-started tour

The Zuiddrecht example-set fragment SHALL mark the getting-started tour as seen for every staff member it gives work to, by writing the per-user value `pref_` plus the manifest's `walkthrough.completionConfigKey` with the manifest's `version`, so a demo opens on the work and not on the tour.

#### Scenario: A demo employee opens dossiq

- **GIVEN** the Zuiddrecht example set is installed
- **WHEN** Esra Demir opens dossiq for the first time
- **THEN** her `pref_walkthrough_completed_version` holds the manifest version
- **AND** the getting-started tour does not start
