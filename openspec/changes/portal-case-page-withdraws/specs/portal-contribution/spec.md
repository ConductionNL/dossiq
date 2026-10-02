## ADDED Requirements

### Requirement: REQ-PORTAL-021: A resident MUST open their own case on a page that can withdraw it

The resident contribution SHALL declare `pages`. The page with id `mijnZaken` SHALL be the first page that shows the `mijnZaken` collection and SHALL carry a `citizenCase` block on it, so portaliq's case screen (status, amend, documents, withdraw) renders for the case a resident opens. Every other listable collection SHALL keep a page with its collection id as page id, the first create action of its schema, the collection table and the selected row.

#### Scenario: A resident withdraws their Woo request from Mijn zaken
- **GIVEN** a resident with an open Woo request whose case type declares a portal withdrawal
- **WHEN** the resident opens the request from "Mijn zaken" on the portal site
- **THEN** the case page shows the case screen with a withdraw button
- **AND** after confirming, the case shows as withdrawn

#### Scenario: The other collections keep their pages
- **GIVEN** the resident contribution
- **WHEN** portaliq resolves its pages
- **THEN** `berichten` and `verzoeken` each have a page with their create action, table and detail, under their own collection id

### Requirement: REQ-PORTAL-022: Mijn zaken MUST show the status in words

The `mijnZaken` collection SHALL declare `statusLabelField: statusPublicLabel`, a field it projects, so portaliq's "Mijn zaken" list shows the status's public label instead of the statusType uuid in `status`.

#### Scenario: A resident reads the status of their Woo request
- **GIVEN** a resident's Woo request with status Ontvangen
- **WHEN** the resident opens "Mijn zaken" on the portal site
- **THEN** the row shows "Ontvangen", not a uuid
