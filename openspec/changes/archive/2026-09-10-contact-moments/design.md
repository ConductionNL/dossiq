# Design: contact-moments

## Context

`contactmoment` (`lib/Settings/register.d/40-kcc-werkplek.json`, version
1.1.0) carries `notificationChannel` (phone, email, webformulier, chat,
social_media, balie), `direction` (inbound, outbound), `startTime`,
`summary`, `nature`, `kccEmployeeId` and `relatedCases`, an array of case ids.
`ContactMomentService::create` writes it and `ContactMomentController`
serves `POST` and `GET /api/contactmomenten` for the KCC workplace, filtered
on `geidentificeerdeBurgerId`. `30-kcc.json` holds `customerContact`, the
older KCC schema, with `direction`, `summary` and `startedAt` and no case
reference at all. `CaseDetail` (`src/manifest.json`) renders its panels in
the `tabs` widget `case-panels`; `case-tasks` is the `object-list` shape this
change copies, with `filter: {case: "@objectId"}`. Its header actions carry
`open-form` entries; `log-hours` passes the case through `props`.

ADR-032 kind: **config**. One schema property, one widget, one tab entry and
one header action. The service change is a two-line copy.

## Goals / Non-Goals

**Goals:**

- A contact moment names the one case it is about, in English.
- The case page lists its contact moments with date, channel, direction and
  summary.
- You log a contact from the case with the case already filled in.

**Non-Goals:**

- Merging `customerContact` into `contactmoment`. Two schemas for one thing
  is a defect, but it is the KCC integration's, not this row's.
- A contact picker on the form. `callerIdentification` stays free text.
- The KCC agent panel (findings B20). This is the case side only.

## Decisions

### D1: `case` is a single reference beside `relatedCases`

`contactmoment.case` is
`{"type": "string", "title": "Case", "description": "The case this contact is about", "$ref": "#/components/schemas/case", "objectConfiguration": {"handling": "related-object"}}`,
matching how `caseTask.case` points at its case. `relatedCases` stays: the
KCC workplace links one call to several cases, and `CaseVoorbladService`
reads it. A contact logged from the case page sets `case`; `create` copies
it into `relatedCases` when that list is empty, so the voorblad sees the
contact too. English identifier per decisions D13; the schema's other
Dutch names (`geidentificeerdeBurgerId`) are out of scope.

### D2: the Communication tab is an `object-list` over `contactmoment`

Widget `case-communication`, type `object-list`, register `dossiq`, schema
`contactmoment`, `filter: {case: "@objectId"}`, sorted on `startTime`
descending, columns `startTime` (Date), `notificationChannel` (Channel),
`direction` (Direction) and `summary` (Summary), `emptyText` "No contact
logged on this case yet". It joins `case-panels` as the tab Communication
and, like its siblings, is absent from `layout`.

### D3: Log contact is an `open-form` header action with the case in `props`

Header action `log-contact` on `CaseDetail`, type `open-form`, schema
`contactmoment`, `includeFields` `notificationChannel`, `direction`,
`startTime`, `summary`, `callerIdentification`, and
`props: {case: "@objectId"}`, the shape `log-hours` already uses to pass
the case into a humaniq form. The required KCC fields the form does not ask
for (`identificationMethod`, `kccEmployeeId`, `nature`) are filled by
`ContactMomentService::create` from the session and defaults, as the KCC
path already does for `kccEmployeeId`.

### D4: Notes and Mail become sections of Communication, later

Zaaksysteem shows notes, contact moments and mail in one pane. A `tabs`
entry names one widget, so the tab cannot hold `case-notes` and
`case-email` beside `case-communication` today. The request to nextcloud-vue
is a tab entry with several widgets rendered as stacked sections
(`CnBodySections` is the rendering half). Until it lands the Communication
tab holds the contact-moment list and Notes and Mail keep their tabs; the
manifest edit that folds them in is one tab entry, recorded in tasks.md as
blocked.

**Resolved 2026-09-10, by retirement rather than by the fold.** A tab does
hold several widgets as stacked sections now, through the `case-sections`
container type. Notes and Mail are no longer body tabs to fold in:
`page-topology-cleanup` moved both to the sidebar, because one log in two
places is duplication rather than coverage. Folding them back would undo
that. The Communication list is a section of the People tab beside Parties,
which is the shape D4 was reaching for with the collections that are still
body content. See tasks.md 2.2.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | reason |
|---|---|---|
| The case reference | declarative, a schema property | Data on the contact moment. |
| The list and the form | declarative, manifest widgets | `object-list` and `open-form` exist. |
| `relatedCases` seeded from `case` | imperative, two lines in the service | The KCC voorblad reads the list; nothing declarative keeps two fields in step. |

## Seed Data

- `lib/Settings/register.d/46-demo-cases-english.json`: two contact moments
  on one demo case, one inbound phone call and one outbound e-mail, so the
  tab shows rows on first open.

## Risks / Trade-offs

- The interim passes `case` through `props`. If `CnDetailPage` hands
  `props` to the form as initial values the field is prefilled; if it does
  not, the handler picks the case by hand until the nextcloud-vue change
  lands. The e2e asserts the saved object, not the prefill.
- Two case references on one object. `create` keeps them in step on write;
  a KCC write that fills `relatedCases` and not `case` does not show on any
  case page. Accepted: that contact was not logged on a case.
