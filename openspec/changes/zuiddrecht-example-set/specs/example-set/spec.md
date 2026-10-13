## ADDED Requirements

### Requirement: Dossiq ships its part of the zuiddrecht example set (REQ-ZES-001)

Dossiq SHALL ship `lib/Settings/example-sets/zuiddrecht.json` in the format of OpenRegister's example-set loader. The fragment SHALL declare `after: ["opencatalogi"]`. Every object SHALL find its case type, status type, result type, role type and decision type by a lookup on a row dossiq ships, and SHALL NOT declare a case type, status type or result type of its own. Every person, organisation, BSN, KVK number and e-mail address SHALL come from the cast through `{{cast:...}}`. Every date SHALL be relative to the install day.

#### Scenario: The fragment resolves against the shipped register
- **GIVEN** dossiq's merged register (`dossiq_register.json` plus `register.d/`) and the cast of openregister's `zuiddrecht.json`
- **WHEN** every placeholder of the fragment is resolved and every object is validated against its schema
- **THEN** every lookup SHALL find a shipped row, every object SHALL satisfy its schema, and no object SHALL carry a key its schema does not declare

@e2e exclude Example data, not behaviour. The loader that installs it is not merged (openregister#4603). The fragment is checked statically against the merged register; the loader's own `--check` and read-back proof run it against a live instance.

#### Scenario: The set adds cases for the cast residents and businesses
- **GIVEN** an instance with openregister, opencatalogi and dossiq enabled
- **WHEN** `occ openregister:example-set:install zuiddrecht` runs
- **THEN** dossiq SHALL hold a granted parking permit for Henk Bakker with its KCC phone contact, a licence plate change for Henk Bakker with Linda Bakker as Gemachtigde, a report by Fatima El-Amrani with a planned desk appointment, a canopy permit for Slagerij Van der Berg filed under its KVK number, and a handled Woo request of CloudGemeente B.V.

@e2e exclude Needs the unmerged loader (openregister#4603) on a live instance; verified by the loader's read-back proof at install.

### Requirement: The cast employees get dossiq's groups (REQ-ZES-002)

The fragment SHALL add these memberships: Daan, Esra, Thomas and Marleen to `dossiq-medewerkers`; Thomas to `behandelaars` and `dossiq-sensitive`; Marleen to `dossiq-coordinators`; Daan to `kcc`; Noor to `procest-admin`. It SHALL NOT add anyone to Nextcloud's `admin` group.

#### Scenario: The Woo coordinator reads Woo requests and sensitive fields
- **GIVEN** the set is installed
- **WHEN** Thomas Kuiper signs in
- **THEN** he SHALL be a member of `dossiq-medewerkers`, `behandelaars` and `dossiq-sensitive`, and not of `admin`

@e2e exclude Group memberships are written and proven by the loader (REQ-EXS-006 in openregister#4603); dossiq adds no code that could fail here.

### Requirement: The published Woo request shows its decision and its publication (REQ-ZES-003)

The handled Woo request SHALL be at its final status with result Deels openbaar gemaakt, `wooPublicationStatus: published` and a `wooWithheldNote`. Its Woo decision SHALL carry a `wooSummary` and a `wooPublication` whose `publicationId` is the opencatalogi publication "Woo-besluit over de aanbesteding van het zaaksysteem", found by a lookup on its title in the `publication` register.

#### Scenario: The decision names the publication opencatalogi wrote earlier in the set
- **GIVEN** opencatalogi's fragment of the set declares that publication
- **WHEN** the set is checked or installed
- **THEN** the decision's `wooPublication.publicationId` SHALL be that publication's id

@e2e exclude Cross-app ordering is the loader's (REQ-EXS-001, REQ-EXS-004 in openregister#4603); checked statically by resolving the lookup against opencatalogi's fragment.
