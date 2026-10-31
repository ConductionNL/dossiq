# Proposal: organisation-contact-persons

## Why

Board DqOrganisatie has a side card "Hoofdcontact": "MV Marja Verbeek, Voorzitter", her e-mail address, and "Alle 2
contactpersonen". Dossiq cannot draw it, because nothing relates a person to an organisation. `kvkCompany` holds the
Handelsregister data (KvK number, trade name, legal form, address); `brpPerson` holds the BRP data. Neither says who
speaks for a vereniging, a stichting or a company, or in which role.

## What changes

Use the party model OpenRegister already has (`docs/Features/parties.md`): a party is linked to an object in a role,
for a period, and one link per object is the primary party. The organisation is the object; its contact persons are
person parties linked to it in a role; the Hoofdcontact is the primary link.

1. **`kvkCompany` declares which parties it takes**: `partyKinds: [{ key: "person", roles: ["chair", "secretary",
   "treasurer", "contact"] }]` with `linkRoles` labels (Voorzitter, Secretaris, Penningmeester, Contactpersoon), as
   configuration a municipality extends. Nothing is added to the organisation record itself. Who may change the
   contact persons is who may update the organisation (the dossiq register's block), enforced by OpenRegister.
2. **The Hoofdcontact is the primary party** (`PUT .../parties/primary`). Replacing it is already audited by
   OpenRegister (`party.primary-replaced`).
3. **A contact person's own e-mail and phone** live on the person as party addresses: `brpPerson` gets
   `contactAddresses` (`[{ kind, type, value }]`) and names it as its `x-openregister-party.addressesProperty`. These
   are details the person gave, not BRP data. The DqContact board already says "Telefoon en e-mail staan niet in de
   basisregistratie", so they stay out of the BRP fields. A contact person needs no BSN: a name and an address are
   enough.
4. **The organisation page** gets the side card "Hoofdcontact", read from `GET .../parties`: the primary party's
   initials, name, role label and correspondence e-mail, and the link "Alle {n} contactpersonen". That link opens a
   "Contactpersonen" tab showing the existing `parties` widget, which the case page already uses for Betrokkenen.
5. **Example data**: Buurtvereniging Oost with Marja Verbeek (Voorzitter, primary, her own address
   marja.verbeek@zuiddrecht.example) and Kees de Wit (Secretaris, no BSN, a name and an address only).

See `design.md` for the alternatives, the library work and Ruben's decisions.
