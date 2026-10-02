## ADDED Requirements

### Requirement: Every dossiq portal page names its menu group
The contribution MUST declare its pages for every audience it serves, and every page MUST carry a `group`: "Mijn
zaken en verzoeken" for `citizen` and `client`, "Opdrachten en facturen" for `supplier`, "Inspecties" for
`inspector`. No resident page MAY be named "Mijn zaken" or "Berichten", the names of the site's own case list and
inbox. A page MUST still show the `mijnZaken` collection with its detail, so a case opens from the site's case list
and from a notice link.

#### Scenario: The resident menu
- **GIVEN** a resident signed in on the site
- **WHEN** the site builds the menu from dossiq's contribution
- **THEN** dossiq's pages MUST sit under "Mijn zaken en verzoeken" as "Voortgang van uw zaken", "Een bericht beantwoorden" and "Mijn verzoeken"
- **AND** "Mijn zaken" and "Berichten" MUST appear once, as the site's own sections

#### Scenario: A case opens from the site's case list
- **GIVEN** a resident with a dossiq case in the site's "Mijn zaken"
- **WHEN** they open it
- **THEN** the page "Voortgang van uw zaken" MUST open with that case selected

### Requirement: The decision notice says what happened
When the decision on a Woo request case is published for the first time and a resident follows the case
(`portalSubject`), dossiq MUST write one `portalMessage` in portaliq's register, in Dutch only, with subject "Het
besluit op uw Woo-verzoek is gepubliceerd", a body that names the request and holds an absolute link to the
publication page of the site (`/index.php/apps/portaliq/site?route=/publicatie/<id>`), `ruleKey`
`dossiq.wooRequest.published` and `recordLink` `{app: dossiq, collection: mijnZaken, id: <case>}`. The citizen
contribution MUST declare that key in `notifications` and MUST NOT declare a change rule for the publish. A
republish MUST NOT write a second message. A message that cannot be written MUST NOT fail the publish.

#### Scenario: The first publish
- **GIVEN** a Woo request case the resident started on the portal
- **WHEN** the Woo coordinator publishes the decision
- **THEN** the resident's inbox MUST hold "Het besluit op uw Woo-verzoek is gepubliceerd" with a link to the publication on the site
- **AND** the inbox MUST NOT also hold "<case> is bijgewerkt" for that publish

#### Scenario: A republish
- **GIVEN** a case whose decision is already published
- **WHEN** the coordinator publishes it again
- **THEN** no new message MUST be written
