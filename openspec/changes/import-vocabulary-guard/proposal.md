# Proposal: import-vocabulary-guard

## Why

On 11 October the register import on an upgraded instance logged
`PARTIAL IMPORT`: OpenRegister refused two schemas and kept the old ones.

- `case`: the case form declaration maps `hideOnCreate` (#3716, for
  nextcloud-vue 2.79.0). OpenRegister refused it as an unknown key. That is
  fixed in OpenRegister (`extends-form-form-roles`), which now accepts form
  roles.
- `aanvullingsverzoek`: `applicantAnswer` declared `inputControl: textarea`
  (#3562). `inputControl` is the control a list surface renders to filter on a
  field, and OpenRegister accepts only text, select, multiselect, range,
  date-range and boolean. A form already gives a long text its textarea from
  `maxLength`, so the key goes.

Every unit test passed while this shipped, because nothing in dossiq knew what
OpenRegister accepts.

## What changes

- `applicantAnswer` drops `inputControl: textarea`. `aanvullingsverzoek` moves to
  1.3.1 and the register to 0.20.26.
- `tests/schemas/openregister-accepted-vocabulary.json` is a snapshot of what
  OpenRegister's import accepts, read from its published vocabulary and
  extending-forms endpoints.
- `ShippedDeclarationsOpenRegisterAcceptsTest` checks every shipped property
  key, every `inputControl` and `matchType` value and every extending-form role
  against that snapshot, and pins `hideOnCreate` on the case form.

## Dependency

The case schema imports once ConductionNL/openregister
`fix/extends-form-hide-on-create` is merged and deployed. The dossiq change is
safe on its own: it removes a refused value and adds tests.
