# case-management Delta: nextcloud-vue-2-76-object-lock

## ADDED Requirements

### Requirement: A case detail page locks the case it shows

When a person opens a case detail page, the page SHALL send its lock request
to OpenRegister with the real register, schema slug and case id in the URL.
It SHALL send no lock request before all three are known.

#### Scenario: Opening a case

- **GIVEN** a case in the dossiq register
- **WHEN** the person opens its detail page
- **THEN** the lock request goes to `/apps/openregister/api/objects/<register>/<schema>/<case id>/lock`
- **AND** the URL holds no function text and OpenRegister does not answer 404

#### Scenario: The page passes getters

- **GIVEN** the detail page passes the register, schema and id as getter functions
- **WHEN** the lock is acquired
- **THEN** the URL holds the values the getters return, not their source
