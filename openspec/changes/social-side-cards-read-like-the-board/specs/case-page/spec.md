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
