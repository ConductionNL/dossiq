## ADDED Requirements

### Requirement: The case schema's role vocabulary survives a rewrite of the schema

When the case schema is updated by anything (a register import, an upgrade, an admin), the system SHALL write the instance's role types back into its `linkRoles` when they are missing, so a person linked in a role type is shown under the role type's name.

#### Scenario: A window refresh re-imports the register
- GIVEN the case schema's link roles hold the role type "Gemachtigde" (3c0f5a00-0000-4000-a000-0000000c0002)
- WHEN the register is imported again and the case schema's link roles are reset to the generic party roles
- THEN the role types are written back
- AND J. van Wijk's row in Rollen reads "Gemachtigde", not the uuid
