## Why

Ruben decided (option a1) that dossiq's Woo request becomes a plain portal create action, so the resident's half-filled request can be a draft (decision 180). The work the Woo endpoint did on top of the write must then happen no matter which path writes the case:

- keep the request as sent, with its origin
- name the requester's party
- fill the case type's own property definitions from the answers
- link the case to the resident's dossier

That work is configuration of a case type, not Woo code (decision 182).

## What Changes

- A case type MAY declare `portalIntake`:
  - `answersAt`: the case field that holds the answers as sent, for example `wooRequest`.
  - `origin`: written into the answers when they name none, for example `portal`.
  - `copy`: an answer kept with the answers from the case field it landed on, for example `onderwerp` from `title`.
  - `party`: when true, a case without `portalParty` gets `subject:<portalSubject>`.
  - `properties`: when true, answers whose names are property definitions of the case type fill the case's `properties`.
  - `dossierAnswer`: the answer naming a dossier of the resident's. The received case is linked to that dossier when the resident owns it.
- When a case of such a type is received (created as not a draft, or sent from a draft), dossiq applies this before the case is stored. It links the dossier once the case exists. A case type without `portalIntake` is untouched, and nothing runs on a draft.
- The Woo request case type declares `portalIntake`. The resident's Woo action (`startWooVerzoekAlgemeen`) becomes a `type: create` action on the case register and schema, with `scopeField: portalSubject`. Its defaults are the Woo case type and `intakeChannel: website`, and a `fieldMap` puts `onderwerp` on `title`, `omschrijving` on `description`, and the other answers under `wooRequest` (portaliq create-action-field-map). Its draft declaration now applies.
- `WooRequesterProperties` now delegates to the generic `CaseTypeProperties`.
- The Woo endpoint stays for its other callers (pipelinq, opencatalogi).
- The submission ledger is portaliq's and OpenRegister's for a create: every send carries its key (REQ-AFS-001).
