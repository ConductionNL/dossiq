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

The words are dossiq's and live in the collection's `valueLabels` (portaliq
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

The block type names below are the ones this change asks `site-mijn-omgeving-components` to
define. If that change names them differently, the names here follow it.

| Page id | Label | menu | Blocks, in order |
| --- | --- | --- | --- |
| `overzicht` | Overzicht | portaliq's home slot | `actionList` on `vragenAanU` (open only), `caseCards` on `mijnZaken` (open only, limit 5, link "Alle zaken, ook afgeronde"), `messageList` on `berichten` (limit 3), `cta` × 3 (Woo-verzoek, bezwaar, klacht) |
| `mijnZaken` | Uw zaak | false | `record` header from `mijnZaken` (`identifier`, `title`, status badge, deadline sentence), `actionList` on `vragenAanU` for this case, `processSteps` (`steps`), `fileList` (`documents`), `contactTimeline` (`timeline`), side: `detail` "In het kort" (`identifier`, `receivedAt`, `deadline`, `assignedGroupPublicName`), `cta` "Bericht sturen" (`replyToMessage`), `citizenCase` (withdraw) |
| `berichten` | Berichten | false | `collection`, `detail`, `action` `replyToMessage` |
| `verzoeken` | Mijn verzoeken | false | `action` `createKlacht`, `collection`, `detail` |

`mijnZaken` stays the first page with a `collection`, `detail` or `citizenCase` block on the
case collection, so portaliq's `navKeyFor` still opens a case there (#3247). Page ids stay the
collection ids, so routes like `/mijn/dossiq/mijnZaken` do not move. The `group` of #3245
stays on every page; it only matters when a page is in the menu.

`menu: false` is a new page key. Portaliq's `buildNav` puts every page in the menu today and
no open portaliq change lets a provider opt out. This change asks
`site-mijn-omgeving-components` to honour it. Until it does, the key is ignored and #3245's
renamed entries show, which is today's state.

The overview is the resident's landing page. Portaliq decides which contribution's overview is
the home when several apps declare one; that is portaliq's (`dashboard-page`).

## D5. The signed-out home

The home in `DossiqHome.dc.html` is a site page an editor builds. Dossiq offers it the start
points: each create or endpoint action a resident may start gains a `summary` (one sentence,
from the mockup) and `audiences`. The widget palette (`site-nlds-widget-palette`) lists them in
a "Wat wilt u regelen?" tile grid. A signed-out visitor who picks one signs in first.

| Action | Tile title | Summary |
| --- | --- | --- |
| `startWooVerzoek` | Informatie opvragen (Woo-verzoek) | Vraag documenten van de gemeente op. Dit kan dankzij de Wet open overheid (Woo). |
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
