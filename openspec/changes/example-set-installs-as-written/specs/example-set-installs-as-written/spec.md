# example-set-installs-as-written

## ADDED Requirements

### Requirement: The Zuiddrecht fragment passes the install check

Every object in dossiq's Zuiddrecht fragment SHALL refer only to objects of the fragment written before it, and every value SHALL have the type its schema declares. The group `dossiq-sociaal-domein` SHALL have at least one member who is not in `dossiq-sensitive`.

#### Scenario: The check passes for dossiq's part
- **GIVEN** an instance with dossiq's register loaded
- **WHEN** an administrator runs `occ openregister:example-set:install zuiddrecht --check`
- **THEN** no line SHALL refuse an object of `dossiq/lib/Settings/example-sets/zuiddrecht.json`

### Requirement: A case of a special-category case type says which categories it processes

Every case in dossiq's Zuiddrecht fragment (`zuiddrecht.json` and `zuiddrecht.d/`) whose case type sets `requiresSpecialCategory` SHALL carry a non-empty `gdprClassification`, within the case type's `specialDataCategories` when it lists them. The install's save check refuses such a case otherwise, although `--check` passes (only the schema is validated there), and every object that refers to the case is refused with it.

#### Scenario: The Oosthaven Jeugdwet case is written
- **GIVEN** a fresh instance with the jeugdwet and re-integratietraject packages
- **WHEN** an administrator runs `occ openregister:example-set:install zuiddrecht`
- **THEN** no line SHALL say "Choose the special categories of personal data this case processes" for an object of dossiq's fragment
- **AND** `jeugdwet-gezin-oosthaven` and `procesanalyse-reintegratie-werkervaringsplek` SHALL be written
