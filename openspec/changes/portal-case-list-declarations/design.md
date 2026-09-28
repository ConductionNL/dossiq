# Design: portal-case-list-declarations

Read at dossiq `development` `c8ac7427e` and portaliq `development`
`8934e73`.

## Context

- `lib/Portal/PortalContributionProvider.php`: `getAudiences()` (:245)
  answers `['supplier', 'citizen', 'inspector']`; `getContribution()` (:288)
  answers the citizen manifest only for `citizen`, the supplier manifest
  (tenders, contracts, invoices, messages) for `supplier`.
  `citizenCollections()` (:463) declares `mijnZaken` on `case`, scoped by
  `portalSubject`, with `fields` from `CITIZEN_CASE_FIELDS` (:114, includes
  `identifier` and `endDate`), `columns`, `detail` and `timeline`, and no
  `kind`.
- `lib/Settings/dossiq_register.json`, schema `case`: `identifier` (the case
  number, generated `{year}-{seq:4}`), `endDate` ("Date the case was
  completed"), `portalSubject` (server-stamped pseudonymous portal subject),
  `initiatorType` and `initiatorSourceId` (KvK number for a company). No
  branch field. Schema `caseType`: no portal field.
- portaliq, what it reads:
  - `lib/Service/PortalCaseListReader.php:95` keeps only collections with
    `kind: cases`; `cases-my-cases-page` D2: "A row is closed when that field
    is present and not empty", and `closedField` must be a projected field.
  - `signin-eherkenning-branch` D2: a session with `branchRestricted: true`
    reads only rows whose `branchField` equals the branch, and none of a
    collection without `branchField`; "Writes stamp `branchField` from the
    session when it is set".
  - `identity-ways-in-screens` D2: a reference session reads only a
    collection whose case type admits the `reference` kind
    (`portalIdentityKind`) and that declares `referenceField`, and exactly the
    row whose `referenceField` equals the claim.
  - `lib/Service/OidcClaimMapperService.php`: preset audiences `digid` and
    `eidas` are `client`, `eherkenning` is `supplier`.
  - `lib/Controller/PortalIdentityController.php` `requestReferenceLink()`
    stores the case number and the address and mails a link; it does not
    check that the address belongs to the case.

## D1. The case collection reaches the audiences a login carries

`getAudiences()` adds `client`. `getContribution()` answers the citizen
manifest for `client` and `citizen`. For `supplier` it answers the supplier
manifest plus the case collection, because a company that signs in with
eHerkenning to follow its own permit case gets audience `supplier`. The case
collection is built by one method so all three audiences read the same
declaration. `citizen` stays for sessions minted before this change.

Alternative considered: ask portaliq to map DigiD to `citizen`. Rejected:
`client` is portaliq's vocabulary for a resident in every open portaliq
change (`portalWritable.audiences: ["client"]`), and pipelinq and shillinq
already serve it.

## D2. Listed on "My cases", open or closed

`mijnZaken` declares `kind: 'cases'` and `closedField: 'endDate'`. `endDate`
is already projected and is set when a case ends, so the closed marker needs
no new field.

## D3. The branch a company filed under

A new case property `portalBranch` (string, the 12-digit
vestigingsnummer, not shown on staff forms) in a register fragment
`lib/Settings/register.d/75-portal-case-declarations.json`, projected on
`mijnZaken` and declared as `branchField`. Where a case is opened from what a
portal session wrote (the intake fan-out, `lib/Service/Intake/IntakeFanOut.php`,
merges the submission into the new case), dossiq copies the branch the portal
stamped on the submission onto the case. Cases filed before this change have
no branch, so a branch-restricted session does not see them, which is the
answer portaliq's design calls safe.

## D4. The case number, and why no case type admits it yet

`mijnZaken` declares `referenceField: 'identifier'`. `caseType` gains
`portalIdentityKind` (array of `account`, `reference`, absent means
`account` only), in the same fragment.

portaliq's reference route issues a link for any address to any case number
of a case type that admits `reference`; nothing matches the address to the
case (`requestReferenceLink()` stores both and mails the link). Admitting
`reference` would let anyone who knows a case number read that case. So this
change ships the declaration and seeds no case type with `reference`, and the
case type editor shows the option disabled with the sentence "Available once
the portal checks the address against the case." The portaliq gap is reported
to the coordinator. When portaliq checks the address, dossiq declares
`referenceAddressField` naming the case field that holds the applicant's
address, which is a follow-up because the case holds none today.

## Declarative-vs-imperative decision (ADR-031)

| Behaviour | Path | Rationale |
|---|---|---|
| `kind`, `closedField`, `branchField`, `referenceField` | Declarative, the contribution manifest | Data portaliq reads. |
| `portalBranch`, `portalIdentityKind` | Declarative, a register fragment | Properties. |
| Copying the branch onto a case opened from a portal write | Imperative, the intake path | A value carried from one object to another at creation. |

## Seed data

A case filed by "Bakkerij De Kroon B.V." with `portalBranch` `000012345678`
and one ended case with an `endDate`, both with a `portalSubject`.

## Risks

- **A second audience sees cases.** The case collection is scoped by
  `portalSubject` whatever the audience, so a supplier session reads only the
  cases filed under its own subject.
- **An empty "Closed" tab for old cases without `endDate`.** They list as
  open, which is what they are to dossiq.
