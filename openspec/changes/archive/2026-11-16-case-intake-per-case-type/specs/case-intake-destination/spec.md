## ADDED Requirements

### Requirement: A case type may say what its intake does to a received case

A case type MAY declare `portalIntake`, with these fields:

- `answersAt`: a case field name.
- `origin`: a string.
- `copy`: a map from answer name to case field.
- `party`: a boolean.
- `properties`: a boolean.
- `dossierAnswer`: an answer name.

When a case of that type is received, which means created as not a draft or sent from a draft, dossiq SHALL do the following before the case is stored:

- write `origin` into the answers object at `answersAt` when the answers name no origin;
- for each `copy` entry the answers lack, copy the value of its case field into the answers;
- set `portalParty` to `subject:<portalSubject>` when `party` is true and the case has a `portalSubject` but no `portalParty`;
- when `properties` is true, add one entry to the case's `properties` for every answer whose name is a property definition of the case type, keeping the entries the case already has.

Once the case exists, dossiq SHALL link it to the dossier named by the answer `dossierAnswer`, if that dossier is owned by the case's `portalSubject`.

A draft SHALL get none of this. A case type without `portalIntake` SHALL be left alone. An unreadable case type SHALL leave the save alone.

#### Scenario: A resident's Woo request
@e2e exclude PHPUnit CaseTypeIntakeTest and CaseTypeIntakeListenerTest
- GIVEN the Woo request case type declares `portalIntake: {answersAt: wooRequest, origin: portal, party: true, properties: true, dossierAnswer: collectionId}`
- WHEN Sanne's request is created through the portal with `wooRequest: {verzoekerNaam: "Sanne de Vries", collectionId: <her dossier>}`
- THEN the stored case has `wooRequest.origin: portal`, `portalParty: subject:<her uid>` and a `verzoekerNaam` property entry, and it is linked to her dossier

#### Scenario: A saved draft
@e2e exclude PHPUnit CaseTypeIntakeListenerTest
- GIVEN the same request saved as a draft
- WHEN it is stored
- THEN none of the intake changes are made until it is sent
