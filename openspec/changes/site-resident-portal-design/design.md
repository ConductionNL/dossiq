# Design: site-resident-portal-design

Read at dossiq `development` `59217bc9a`, with the open PRs dossiq#3245
(`fix/woo-r3-portal-notices`) and dossiq#3247 (`feat/woo-withdraw-page-on-site`), and portaliq
`development` `b150def5`, on 2026-10-02.

## D0. What ships today, and what the mockups assume

| Mockup element | Today | This change |
| --- | --- | --- |
| Case list with status in words | `mijnZaken`, `statusPublicLabel` (`citizen-status-labels`), `statusLabelField` (#3247) | kept |
| Case detail fields | `CITIZEN_CASE_DETAIL_FIELDS` | kept, plus three fields |
| "Wat er is gebeurd" | `timeline.provider: caseTimeline` | kept |
| Documents and "Document toevoegen" | `documents.provider: caseDocuments`, portal document window (`portal-citizen-writes-on-the-case`) | label "Documenten" |
| Withdraw as side action | `citizenCase` block (#3247), `portalWithdrawal` on the case type | kept |
| "Bericht sturen" on the case | `replyToMessage` with `caseId` cross-ref | placed on the case page |
| "Wij hebben een vraag aan u" | `aanvullingsverzoek` exists; not served to the portal | NEW: `vragenAanU` |
| "U bent aan zet" | `case.waitingOnApplicant`, `case.waitingOn`; not projected | NEW: projected |
| "Stap 2 van 4" and the steps list | nothing | NEW: `caseSteps` |
| "U bent aan zet" as one card field | nothing | NEW: `portalTurn` |
| "Behandeld door Team Open overheid" | `case.assignedGroup` is a uuid | NEW: `assignedGroupPublicName` |
| One "Zaken", one "Berichten" | three dossiq menu entries (renamed in #3245) | `menu: false` |

## D1. The question to the resident

`InformationRequestService::ask()` writes the `aanvullingsverzoek`. It also writes
`portalSubject`, copied from the case, when the case has one. The request schema gains the
property (string, `visible: false` in the desk UI).

The citizen contribution adds a collection:

```php
[
  'id' => 'vragenAanU',
  'register' => self::REGISTER,
  'schema' => 'aanvullingsverzoek',
  'scopeField' => 'portalSubject',
  'label' => 'Wat wij nog van u nodig hebben',
  'listable' => true,
  'minTrust' => 'low',
  'fields' => ['case', 'summary', 'missingItems', 'hersteltermijn', 'state', 'requestedAt'],
]
```

`rationale`, `party`, `recipient`, `requestedBy`, `pauseReason` and the deadline internals are
not projected. The ask form must then tell the handler that `summary` and `missingItems` go
to the applicant (tasks 1.2).
The overview shows the open ones (`state` `open`); the case page shows the open one for that
case, on top. The resident answers with the existing `replyToMessage` and the portal document
window. Closing the request stays the handler's act, as `aanvullingsverzoek-as-a-record` 2.2
specifies: the handler names which items arrived.

## D2. Who must act

`CITIZEN_CASE_FIELDS` gains `waitingOnApplicant` and `waitingOn`. Both are read-only and
derived. The case card reads them:

| Case | Card text |
| --- | --- |
| `endDate` set | no line |
| `waitingOnApplicant` true, or `waitingOn` `applicant` | "U bent aan zet" |
| `waitingOn` `thirdParty` | "Wij wachten op informatie van een ander" |
| otherwise | "De gemeente is aan zet" |

Portaliq's case card reads one field for this (`turnField`, portaliq
`site-mijn-omgeving-components` REQ-SMO-022), and the table above reads two. So the case
schema gains `portalTurn`, a calculation over `endDate`, `waitingOnApplicant` and `waitingOn`
with the values `applicant`, `thirdParty`, `us`, or empty for a closed case. `mijnZaken`
projects it and declares `turnField: 'portalTurn'` and `dueField: 'deadline'`. The words are
dossiq's and live in the collection's `valueLabels` on `portalTurn` (portaliq
`contribution-value-labels`), so a portal administrator can reword them per site.

## D3. Steps

`caseSteps(caseId)` on the provider, beside `caseTimeline` and `caseDocuments`, backed by a new
`OCA\Dossiq\Portal\CaseSteps`. It reads the case's case type, its statuses by `order`, and the
case's status, then folds consecutive statuses that share a public label into one step. The
answer:

```json
[
  {"label": "Ontvangen", "description": "…", "state": "done", "date": "2026-10-02"},
  {"label": "In behandeling", "description": "…", "state": "current"},
  {"label": "Besluit", "state": "todo"},
  {"label": "Besluit genomen", "state": "todo"},
  {"label": "Afgehandeld", "state": "todo"}
]
```

The label is `publicLabel`, or the status name when the status declares none (the rule of
`citizen-status-labels`). The description is `publicDescription`. The date is when the case
entered the first status of that step, read from the status history. A withdrawn case marks
the current step and stops; later steps are not shown as to do.

The seeded Woo type folds eight internal statuses into five public steps: Ontvangen, In
behandeling (four statuses), Besluit, Besluit genomen, Afgehandeld. The mockup draws four
(Ontvangen, In behandeling, Besluit, Afgerond). The steps come from the case type, never from
the page: if Ruben wants four, the case type's public labels change (tasks 0.2), not this
code.

The collection declares `'steps' => ['label' => 'Waar staat uw aanvraag?', 'provider' => 'caseSteps']`.
The card's "Stap 2 van 5" reads the same answer: the index of the current step and the count.

## D4. Pages

Block types, page keys and collection keys are the ones portaliq `site-mijn-omgeving-components`
defines (portaliq#1110): REQ-SMO-020 (page keys `group`, `menu`, `home`, `records`),
REQ-SMO-021 (blocks `tasks`, `inbox`, `cases`, `steps`, `documents`, `timeline`; `limit` and
`sort` on `collection`) and REQ-SMO-022 (`steps`, `dueField`, `turnField` on a cases
collection).

| Page id | Label | Page keys | Blocks, in order |
| --- | --- | --- | --- |
| `overzicht` | Overzicht | `home: true`, `menu: false`, `group` | `tasks` on `vragenAanU` (`dueField: hersteltermijn`, `titleFields: [summary]`), `cases` on `mijnZaken` (`open: true`, `limit: 5`), `inbox` on `berichten` (`limit: 3`), `cta` × 3 (Woo-verzoek, bezwaar, klacht) |
| `mijnZaken` | Uw zaak | `record: {collection: mijnZaken, titleFields: [title]}`, `menu: false`, `group` | `collection` on `vragenAanU` (`recordField: case`, open only), `steps`, `documents`, `timeline`, `detail` "In het kort" (`identifier`, `receivedAt`, `deadline`, `assignedGroupPublicName`), `cta` "Bericht sturen" (`replyToMessage`), `citizenCase` (withdraw) |
| `berichten` | Berichten | `menu: false`, `group` | `collection`, `detail`, `action` `replyToMessage` |
| `verzoeken` | Mijn verzoeken | `menu: false`, `group` | `action` `createKlacht`, `collection`, `detail` |

On the home, portaliq puts open portal tasks and the rows of every `tasks` block on a home page
together under "Dit moet u nog doen", sorted by deadline, and renders the rest of the home page
under it (portaliq design D4). The open question for one case uses a `collection` block with
the existing record scoping key `recordField`, because the `tasks` block takes no record scope.

`steps`, `documents` and `timeline` render only on a record page whose collection declares that
provider (REQ-SMO-021), which is why `mijnZaken` becomes a record page on its own collection.
It keeps its `detail` and `citizenCase` blocks, so it stays the first page with a `collection`,
`detail` or `citizenCase` block on the case collection and portaliq's `navKeyFor` still opens a
case there (#3247). The build confirms with the portaliq lane that a record page opened through
`navKeyFor` selects the case (tasks 3.1). Page ids stay the collection ids, so routes like
`/mijn/dossiq/mijnZaken` do not move. The `group` of #3245 stays on every page.

`menu: false` keeps the route and leaves the menu (REQ-SMO-020). Before portaliq#1110 lands the
key is ignored and #3245's renamed entries show, which is today's state.

## D5. The signed-out home

The home in `DossiqHome.dc.html` is a site page an editor builds. Dossiq offers it the start
points: each create or endpoint action a resident may start gains a `summary` (one sentence,
from the mockup) and `audiences`. The widget palette (`site-nlds-widget-palette`) lists them in
a "Wat wilt u regelen?" tile grid (REQ-SNW-020: `summary` 1 to 200 characters, `audiences` a
subset of dossiq's audiences). The Woo tile is the action without a dossier
(`site-woo-request-in-steps`). A signed-out visitor who picks one signs in first.

| Action | Tile title | Summary |
| --- | --- | --- |
| `startWooVerzoekAlgemeen` | Informatie opvragen (Woo-verzoek) | Vraag documenten van de gemeente op. Dit kan dankzij de Wet open overheid (Woo). |
| `createBezwaar` | Bezwaar maken | Bent u het niet eens met een besluit? Maak binnen zes weken bezwaar. |
| `createKlacht` | Klacht indienen | Vertel ons wat er misging. |

The mockup's "Binnen vijf werkdagen belt een medewerker u" is a service promise. Dossiq does
not know it is true for an instance, so the summary leaves it out; an editor can add it on the
page.

## Risks

- **A request becomes visible that a handler wrote for colleagues.** Only `summary` and
  `missingItems` are shown, and the ask form says so before the handler sends. A request on a case without `portalSubject` gets none and stays invisible.
- **Two open PRs change the same method.** #3245 and #3247 both edit `citizenContribution()`.
  This change is written against both; the build lands after them.
