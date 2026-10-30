# case-page Specification

## Purpose
What dossiq does for case page. The requirements were first written by the change `screens-round12-domain-case-cards` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-screens-round12-domain-case-cards/`) and cover: The case page shows the cards of its case type's domain (REQ-DCC-1); A case type picks the side cards of its cases; The decision stage of a domain case shows the What now? card; The LHS advice page shows the next act; and 4 more.

## Requirements

### Requirement: The case page shows the cards of its case type's domain (REQ-DCC-1)

`CaseDetail` in the simple profile SHALL show the Hearing, Advice of the
committee and Appeal cards only for a case whose case type category is
"Bezwaar en beroep", the Grant application card only for "Subsidies", the
Consent card only for the social domain or a case naming a family plan, and
the Bids card only for "Inkoop en leveranciers". Each card SHALL list only
records that name this case.

#### Scenario: A permit case shows no objection cards
@e2e exclude measured against boards DqBezwaar and DqZaak by the round 12 pixel check, not an e2e flow

- **GIVEN** a case whose case type category is Vergunningen
- **WHEN** you open the case
- **THEN** the side column SHALL show none of the six domain cards

### Requirement: A case type picks the side cards of its cases

A case type MAY list `sideCards`, ids of the case page's side cards. A case of
a type that lists cards SHALL show those cards, in that order, each still
following its own condition. A case of a type that lists none SHALL show the
default column: deadline, requester, handling and work instruction, then the
domain cards whose conditions hold.

#### Scenario: A case type without the setting
- GIVEN a case of a case type with no `sideCards`
- WHEN the case page opens
- THEN the side column starts with deadline, requester, handling and work instruction

#### Scenario: A case type with the setting
- GIVEN a Melding Jeugdwet case, whose case type lists the banner stack, consent and who
- WHEN the case page opens
- THEN the side column shows those cards in that order and no generic card

### Requirement: The decision stage of a domain case shows the What now? card

The status a domain case type uses for weighing and deciding SHALL carry the
`review` role, and a case type SHALL NOT give one role to two statuses.

#### Scenario: An objection after the committee's advice
- GIVEN an objection case in status "Advies uitgebracht"
- WHEN the case page opens
- THEN the What now? card for the review stage shows

### Requirement: The LHS advice page shows the next act

The LHS advice page SHALL show a What now? card for the intervention the
advice settles on.

#### Scenario: A penalty order
- GIVEN an advice whose final intervention is a penalty order
- WHEN the page opens
- THEN the card asks for the pre-announcement and the two-week period for a view

### Requirement: A case type keeps its own What now? texts

A case type MAY declare `nextStep` with its own stages (title, checklist and what follows per stage). The case page
SHALL read them through `nextStep.stagesFrom` and SHALL keep its own stages for a case type that declares none.

#### Scenario: An objection with its own wording
- GIVEN the Bezwaar case type declares its own text for the review stage
- WHEN an objection in that stage opens
- THEN the What now? card reads the case type's text, and a permit case still reads the page's

### Requirement: The stage action stands aside where the what-now card does

The case page SHALL not draw the stage's primary action in the header beside a tab where the what-now card stands aside; the action SHALL stay under More.

#### Scenario: the AI oversight tab
@e2e exclude config pinned by tests/vitest/stageActionAsideOnAiTab.spec.js; behaviour tested in nextcloud-vue

- **GIVEN** a case in progress
- **WHEN** the handler opens the AI oversight tab
- **THEN** the header SHALL show Bericht, Bewerken, the layers button and Meer, and no Volgende stap

### Requirement: The case plan's note sits beside its title

The simple case page's Zaakplan card SHALL carry its line about the plan as the card's note at the right of the title
row, and no line under the title.

#### Scenario: Zaakplan on a Woo case
- **GIVEN** a case with an adaptive plan, opened on the simple case page
- **THEN** the Zaakplan title row ends with "Mijlpalen en onderdelen van deze zaak"
- **AND** the first plan row follows the title row

### Requirement: The social and enforcement Historie cards draw compact rows

The side card Historie of a social-domain case and of an enforcement advice SHALL draw its stacked rows compact, 10px
above and below, the first row 10px under the title, as DqSociaalDomein and DqHandhaving draw them.

#### Scenario: DqSociaalDomein Historie

- **GIVEN** a Melding Jeugdwet case in the board look
- **WHEN** its Historie card lists "Inzage medisch verslag" with "Fatma Kaya · doel onderzoek · 6 okt"
- **THEN** the action starts 21px under the card title and the row is padded 10px above and below

#### Scenario: DqHandhaving Historie

- **GIVEN** an enforcement advice in the board look
- **WHEN** its Historie card lists "Advies berekend" with "Systeem · 24 okt 13:37"
- **THEN** the row is padded 10px above and below
