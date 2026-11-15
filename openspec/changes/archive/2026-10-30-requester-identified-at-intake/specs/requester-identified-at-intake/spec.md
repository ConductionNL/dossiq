# requester-identified-at-intake Delta

**Status**: proposed
**Scope**: dossiq

## Purpose

A case taken in through a portal says how the requester logged in and which portal it came through.

## ADDED Requirements

### Requirement: A portal intake records how the requester logged in and where

A case opened from a portal action SHALL carry `intakeIdentification`, read from the verified portal assertion's audience
only (`citizen` or `client`: `digid`; `business`: `eherkenning`), and `intakeSourceName`, portaliq's configured portal
name. Neither SHALL be read from the request body. An audience with no known means and an empty portal name SHALL leave
the field out.

#### Scenario: A resident starts a Woo request on Mijn Zuiddrecht

- **GIVEN** portaliq's portal name is "Mijn Zuiddrecht"
- **WHEN** a resident logged in with DigiD (audience `client`) starts a Woo request
- **THEN** the case has `intakeIdentification` `digid` and `intakeSourceName` "Mijn Zuiddrecht"

#### Scenario: A body that names a means

- **WHEN** the request body carries `intakeIdentification: eherkenning` and the assertion's audience is `client`
- **THEN** the case has `intakeIdentification` `digid`

### Requirement: The case shows the requester's identification and the portal

The case's requester card SHALL show "Ingelogd met DigiD" or "Ingelogd met eHerkenning" under the requester's name when
the case has an `intakeIdentification`, else the requester's kind, and SHALL link "Contactgegevens bekijken" to the
contact page for a person and the organisation page for a company. It SHALL NOT list the intake channel. The case header
SHALL read "via {intakeSourceName}" and leave the line out when the case has none.

#### Scenario: DqZaak Aanvrager

- **GIVEN** a case with requester Sanne de Vries (a person), `intakeIdentification` `digid`, `intakeSourceName` "Mijn Zuiddrecht"
- **THEN** the card reads "Sanne de Vries", "Ingelogd met DigiD" and "Contactgegevens bekijken", which opens her contact page
- **AND** the header reads "via Mijn Zuiddrecht"
