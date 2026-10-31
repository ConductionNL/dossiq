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
- OrganisationDetail: the side card `side-main-contact` and the tab "Contactpersonen" (`parties` widget, tab id
  `org-contact-persons`, counted from the listing's `total`). The tab's widget declares `manage`: "Contactpersoon toevoegen" picks a
  known person or describes a new one without a BSN (name, e-mail, phone, address), a role, and optionally makes them
  the main contact (through the primary route, which is audited); each contact person can be removed from a role.
  The buttons hide when OpenRegister's scopes deny update, and every write goes through the party API.
- Example set: the two contact persons on Buurtvereniging Oost.
- l10n: role labels and the card words.

## Decisions (Ruben, 24 Oct)

1. **Roles: Voorzitter, Secretaris, Penningmeester, Contactpersoon, as configuration.** `kvkCompany` declares them as
   `linkRoles` (`chair`, `secretary`, `treasurer`, `contact`) and its `person` party kind accepts exactly those. A
   municipality extends the list by adding a role to both; nothing in code names a role. The manifest gives the four
   their words (`roleLabels`), and a role the manifest does not name shows the label the schema declares.
2. **The card shows the contact person's OWN correspondence e-mail.** It is the person's party address
   (`brpPerson.contactAddresses`, kind `correspondence`), cached on the link by OpenRegister. The organisation's
   mailbox (bestuur@...) stays on the organisation and is not shown as the contact's address. The board is amended
   to Marja's own address.
3. **Editing contact persons is the organisation's update right, enforced through OpenRegister RBAC.** Server side:
   OpenRegister's party writes (link, unlink, replace primary) ask `update` on the object
   (openregister `party-writes-need-update-right`, REQ-PRM-005), so a user who may read an organisation but not update
   it gets a 403 on every party write. `kvkCompany` keeps no authorization block of its own: the dossiq register's
   block decides (staff and the background account read and update; nobody else even reads). A block of its own
   would replace the register's for organisations, and `read: authenticated` would open them to every account.
4. **Contact persons without a BSN are allowed.** `brpPerson.citizenServiceNumber` is no longer required: a person
   party may hold only a name, an address and contact addresses. The BRP registry sync keys on the BSN, so a person
   without one is never matched or overwritten by it; the pattern and the 11-proef still apply whenever a BSN is set.
