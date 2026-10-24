# Tasks: organisation-contact-persons

- [x] 1.1 Decide roles, the e-mail owner, edit rights and non-BSN contact persons (Ruben, 24 Oct; design.md).
- [x] 2.1 nextcloud-vue: `CnObjectPartiesWidget` `presentation: "primary"` with the count link (test first;
  feat/object-parties-primary-presentation).
- [x] 2.2 openregister: party writes need the update right on the object (feat/party-writes-need-update-right).
- [x] 3.1 dossiq register fragment: `kvkCompany` partyKinds/linkRoles (update right from the register block); `brpPerson` contactAddresses +
  addressesProperty, BSN optional.
- [x] 3.2 dossiq OrganisationDetail: side card "Hoofdcontact" and tab "Contactpersonen".
- [x] 3.3 Example set: Marja Verbeek (Voorzitter, primary) and Kees de Wit (Secretaris, no BSN) on Buurtvereniging Oost.
- [ ] 3.4 Live: DqOrganisatie "Hoofdcontact" on :8080 (after the nc-vue release and a Zuiddrecht set re-install).
- [ ] 4.1 Later: an add/remove flow in the parties widget ("Contactpersoon toevoegen"); until then contact persons are
  linked through the OpenRegister API.
