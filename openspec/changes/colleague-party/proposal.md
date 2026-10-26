# Proposal: colleague-party

## Why

The boards draw colleagues as parties on a case: Lars de Koning as "Collega", and an "Adviseur" on case 710d44a4. A party
link's kind comes from the party's schema (`x-openregister-party.kind`), and dossiq only had `person` (brpPerson) and
`organisation` (kvkCompany), so a colleague could not be linked as one. The parties widget also marked every party "Soort
niet geaccepteerd". It compared these schema kinds with pipelinq's role kinds, a different vocabulary.

## What changes

- A `colleague` schema (register.d/47-colleague-party.json): a party of kind `colleague`, named by `displayName` and tied
  to a Nextcloud account by `userId`, with `email`, `jobTitle` and `department`.
- The case schema accepts `colleague` (case 1.53.0). Each party kind names its `identityShapes` (`person` and `colleague`
  → `naturalPerson`, `organisation` → `organization`), and so does the organisation's contact person (kvkCompany 1.6.0).
  nc-vue's parties widget checks a party against pipelinq's role kinds by these shapes (nextcloud-vue
  fix/parties-accepted-kinds-by-shape), and OpenRegister keeps the key (feat/party-kind-identity-shapes; an older
  OpenRegister drops it, and the widget then cannot judge, so it passes the party).

Procedures stay configuration: the colleague's role on a case is the case type's role (Adviseur, Collega); only the kind
is generic.

## Scope

`lib/Settings`, the schema digests, and translations. DQ-DATA seeds Lars de Koning as a colleague.
