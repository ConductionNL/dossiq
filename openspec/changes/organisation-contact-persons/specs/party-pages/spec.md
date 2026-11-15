# party-pages Delta

**Status**: building
**Scope**: dossiq
**OpenSpec changes**:

- [organisation-contact-persons](../../)

## ADDED Requirements

### Requirement: An organisation names its contact persons and its main contact

An organisation SHALL be able to hold person parties in a contact role (Voorzitter, Secretaris, Penningmeester,
Contactpersoon), exactly one of them as its main contact. The organisation page SHALL show the main contact with
their role and correspondence address, and a link to all contact persons with their count.

#### Scenario: DqOrganisatie

- **GIVEN** Buurtvereniging Oost with Marja Verbeek as Voorzitter (main contact) and one more contact person
- **THEN** the side card "Hoofdcontact" reads "MV Marja Verbeek, Voorzitter", her own e-mail address
  (marja.verbeek@zuiddrecht.example, not the organisation's mailbox), and "Alle 2 contactpersonen"
- **AND** that link opens the tab "Contactpersonen", which lists both by role

### Requirement: The roles a contact person can hold are configuration

The roles SHALL be declared on the organisation schema (`linkRoles`, and the roles its `person` party kind accepts),
Voorzitter, Secretaris, Penningmeester and Contactpersoon out of the box. A role SHALL be added by configuration
only, and a role the app has no word for SHALL show the label the schema declares.

#### Scenario: the shipped roles

- **GIVEN** a fresh install
- **THEN** `kvkCompany` accepts person parties in the roles `chair`, `secretary`, `treasurer` and `contact`, labelled
  Voorzitter, Secretaris, Penningmeester and Contactpersoon
- @e2e exclude {register configuration, covered by PHPUnit on the register fragment}

### Requirement: Only who may update the organisation may change its contact persons

Linking, unlinking and replacing the main contact SHALL need the organisation's update right, enforced by OpenRegister
on the server. A user who may only read the organisation SHALL be refused, and nothing SHALL change.

#### Scenario: a reader cannot link or unlink

- **GIVEN** a user who may read Buurtvereniging Oost but not update it
- **WHEN** that user links a person as Penningmeester, or takes Marja off
- **THEN** OpenRegister answers 403 and the contact persons are unchanged
- @e2e exclude {authorization, covered by openregister PartyControllerTest and the dossiq register fragment test}

### Requirement: A contact person may have no BSN

A person party SHALL be valid with only a name and an address. A BSN, when given, SHALL still be checked.

#### Scenario: Kees de Wit, secretary without a BSN

- **GIVEN** a person with a name and an address and no BSN
- **WHEN** the person is saved and linked to Buurtvereniging Oost as Secretaris
- **THEN** the save succeeds and the contact persons tab lists Kees de Wit under Secretaris
- @e2e exclude {register configuration, covered by PHPUnit on the register fragment}

### Requirement: A handler adds and removes contact persons on the organisation

The Contactpersonen tab SHALL let a user who may update the organisation add a contact person (somebody already
known, or somebody new with a name, contact details and an address and no BSN) in one of the configured roles,
optionally as the main contact, and remove a contact person from a role after a confirm.

#### Scenario: Add a treasurer who is new

- **GIVEN** Pieter on Buurtvereniging Oost's Contactpersonen tab
- **WHEN** he chooses "Contactpersoon toevoegen", describes Ans Bos with her e-mail address, chooses Penningmeester
  and confirms
- **THEN** a person without a BSN is saved and listed under Penningmeester, and the tab counts 3
- @e2e exclude {needs the nc-vue release with the manage widget; measured live after it}

