# Design: organisation-contact-persons

## The relation

| Option | What it is | Verdict |
|---|---|---|
| **A. OpenRegister parties on the organisation** | person party ↔ `kvkCompany`, typed role, period, one primary link | **Chosen** |
| B. A dossiq `organisationContact` schema (`organisation`, `person?`, `name`, `role`, `email`, `isPrimary`) | the pipelinq `contact.client` + `role` shape | Rejected: it duplicates the party model; merge, indicators and the primary audit would have to be rebuilt |
| C. Read pipelinq contacts through a leaf, by `masterEntityRef` | as the Tickets card does | Rejected: dossiq must work without pipelinq, and the contact person is not CRM data only |
| D. An embedded array `contactPersons[]` on `kvkCompany` | name, role, e-mail per entry | Rejected: no person identity, so it cannot be counted, filtered or opened, and goes stale when the person is corrected |

Why A:

- The model exists. Roles are a vocabulary per schema (`partyKinds`, `linkRoles`), a period is part of a link, and
  "primary" is a single audited link. That is exactly "Hoofdcontact" and "Alle 2 contactpersonen".
- One person, one record. The organisation's chair who is also a requester on a case is the same `brpPerson`, with
  the same indicators (Geheimhouding travels with her).
- Cross-app: pipelinq's `contact.client` + `role` and shillinq's organisation contacts can map onto the same links
  later, by `masterEntityRef`. That is the "contact person and organisation screens (almost) identical" rule.
- The reverse is free: `GET /api/parties/{uuid}` lists every object a person is on. A later DqContact card can then
  read "Voorzitter van Buurtvereniging Oost".

## Library work (nextcloud-vue)

- `CnObjectPartiesWidget` gets `content.presentation: "primary"`: only the primary party, drawn as the board's lead
  (initials tile, name, role label as the muted line), then the party's first correspondence address (`mailto:`), then
  a footer link "Alle {count} contactpersonen" to a tab. The full widget is unchanged.
- No new endpoint: `GET .../parties` already returns `primary`, `byRole` and `total`.

## Dossiq work

- `register.d` fragment: `kvkCompany` `partyKinds` / `linkRoles`; `brpPerson` `contactAddresses` and
  `addressesProperty` (schema bumps, register version bump).
- OrganisationDetail: the side card `side-main-contact` and the tab "Contactpersonen" (`parties` widget). A header or
  card action "Contactpersoon toevoegen" uses the existing parties add flow.
- Example set: the two contact persons on Buurtvereniging Oost.
- l10n: role labels and the card words.

## Open questions (for Ruben)

1. **Roles.** Is it Voorzitter, Secretaris, Penningmeester, Contactpersoon, or a different list? The list is config
   (`linkRoles`), so it can grow per municipality.
2. **Whose e-mail.** "bestuur@buurtverenigingoost.nl" reads as the organisation's mailbox, not Marja's. Should the
   card show the person's correspondence address (proposed), or an address on the organisation?
3. **Who may edit.** Should only the handlers who may edit the organisation link contact persons, or anyone who may
   read it? Proposed: the organisation's update right, through OpenRegister RBAC.
4. **Non-resident contact persons.** A contact person outside the municipality is still a `brpPerson` (BRP/RNI).
   Should dossiq also allow a contact person without a BSN, a party with only a name and an address?
