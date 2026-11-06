# Proposal: case-roles-survive-a-schema-reimport

## Why

DqZaakBetrokkenen "Rollen": every row's meta line names its role as a uuid ("3c0f5a00-0000-4000-a000-0000000c0002") instead of "Gemachtigde", which also makes the rows wrap (88.5 against the board's 75). People are linked in a role type, keyed by its uuid, and CaseRoleVocabulary writes the role types into the case schema's `linkRoles` so the Roles card can name them. The shipped register declares only the six generic party roles there, and every import of the register (an upgrade, a window refresh) writes that back, so the role types were gone again. On the dev instance the case schema held the six generic roles and none of the five role types.

## What changes

- A listener on OpenRegister's SchemaUpdatedEvent re-syncs the vocabulary when the case schema is updated. The sync writes nothing when the schema already carries the vocabulary, so its own update ends there.
