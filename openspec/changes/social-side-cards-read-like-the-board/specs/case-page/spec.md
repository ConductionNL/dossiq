## ADDED Requirements

### Requirement: The social domain side column reads like its board

A Melding Jeugdwet case SHALL show its side cards in the order notice,
Consent, Who, Status. Consent SHALL show the newest consent on the case as
label-left facts (purpose, given by, recorded with date and channel, valid
until), and Who SHALL show each person on the case behind their initials with
their name and role on one line.

#### Scenario: the Oosthaven case
- GIVEN the Melding Jeugdwet case of the Oosthaven family with a written consent by both parents for "Onderzoek naar jeugdhulp" and no end date
- WHEN the case page opens
- THEN the side column reads Bijzondere persoonsgegevens, Toestemming, Wie, Status in that order
- AND Toestemming reads "Doel: Onderzoek naar jeugdhulp", "Gegeven door: Beide ouders", "Geldig tot: Tot intrekking"
- AND Wie reads "FK Fatma Kaya, regisseur, jeugdteam"

### Requirement: A consent can be withdrawn from its card

The Consent card SHALL carry "Withdraw consent" while the newest consent may be
withdrawn and is not withdrawn yet. Confirmed, it SHALL mark the consent
withdrawn and end it today, changing no other field.

#### Scenario: the parents withdraw their consent
- GIVEN the Oosthaven consent, withdrawable and not withdrawn
- WHEN the handler chooses "Toestemming intrekken" and confirms
- THEN the consent is PATCHed with `withdrawn: true` and `validTo` today

### Requirement: The social domain side column ends with the case's history

On a case type that holds special categories of personal data the side column
SHALL show, before Status, the case's latest audit events without reads, and
an access purpose SHALL read as the purpose the reader gave.

#### Scenario: Fatma opened the case for handling
- GIVEN Fatma Kaya opened the Oosthaven case stating the purpose "handling"
- WHEN the case page opens
- THEN Historie lists "Zaak ingezien, doel behandeling" by Fatma Kaya

### Requirement: A social domain case offers to plan a case meeting

A social domain case that is not archived SHALL offer "Plan a case meeting",
which asks the meeting's date and agenda and files the meeting on this case.
Melding Jeugdwet SHALL list it as its quick action, above the case.

#### Scenario: Overleg plannen on the Oosthaven case
- GIVEN the Melding Jeugdwet case of the Oosthaven family
- WHEN the case page opens
- THEN "Overleg plannen" stands above the case and opens a form for the date and agenda of a meeting on this case
