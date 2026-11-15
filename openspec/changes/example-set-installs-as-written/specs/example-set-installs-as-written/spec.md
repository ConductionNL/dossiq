# example-set-installs-as-written

## ADDED Requirements

### Requirement: The Zuiddrecht fragment passes the install check

Every object in dossiq's Zuiddrecht fragment SHALL refer only to objects of the fragment written before it, and every value SHALL have the type its schema declares. The group `dossiq-sociaal-domein` SHALL have at least one member who is not in `dossiq-sensitive`.

#### Scenario: The check passes for dossiq's part
- **GIVEN** an instance with dossiq's register loaded
- **WHEN** an administrator runs `occ openregister:example-set:install zuiddrecht --check`
- **THEN** no line SHALL refuse an object of `dossiq/lib/Settings/example-sets/zuiddrecht.json`
