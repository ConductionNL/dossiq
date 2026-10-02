# Design: site-business-and-authorisation

Read at dossiq `development` `59217bc9a` and portaliq `development` `b150def5` on 2026-10-02.
Portaliq paths are on that commit.

## D0. Who owns which piece of the two mockups

| Mockup element | Owner | Where it lives |
| --- | --- | --- |
| "Ingelogd als", eHerkenning login | portaliq | `OidcClaimMapperService`, `portal-ways-in` |
| "U regelt nu zaken voor Slagerij Van der Berg, KVK 12345678" bar | portaliq | `ActingForSwitcher`, REQ-PIOC-008 |
| "Voor wie regelt u nu zaken?" (uzelf, het bedrijf, H. Bakker) | portaliq | `ActingForSwitcher`, `BranchSwitcher`, REQ-CMC-004 |
| "Wie mag zaken regelen voor uw bedrijf?" list, "Iemand machtigen", "Intrekken", "Uitnodiging intrekken" | portaliq, NOT BUILT | `portalMandate`; gap listed in the proposal |
| "Uw machtiging": scope, valid until, given by, "Machtiging stoppen" | portaliq, partly | `portalMandate.label`, `expiresAt`, `grantedBy`, `grantedAt`; stop not built |
| "Dit moet u nog doen", case cards, "Lopende zaken van uw bedrijf" | dossiq declares, portaliq renders | this change and `site-resident-portal-design` |
| Which cases a company or a represented person sees | dossiq declares the party, portaliq filters | `portalParty`, `mandateField` |
| "Hij ziet alles wat u hier doet" | portaliq records, dossiq shows | `portalWrites`, `caseTimeline` |

## D1. The business audience

`getAudiences()` returns `['supplier', 'citizen', 'client', 'business', 'inspector']`.
`getContribution()` answers `business` with the citizen manifest built for a company:

- the same collections as the resident (`mijnZaken`, `berichten`, `vragenAanU`, `verzoeken`),
- the same create and endpoint actions,
- the pages of `site-resident-portal-design` with these labels changed: the case heading
  "Lopende zaken van uw bedrijf", the account group "Uw bedrijf".

`PortalWooRequestController::AUDIENCES` adds `business`, so a company can file a Woo request.

A municipality portal sets `claimMap.audience: business` on its eHerkenning provider. Portaliq
supports this per organisation today (`OidcClaimMapperService.php` line 162). A portal that
keeps the preset gets `supplier`, which receives the case collection beside the procurement
pages, as `portal-case-list-declarations` D1 decided.

Alternative considered: answer `supplier` with the citizen manifest and drop procurement.
Rejected: the procurement portal (`supplier-portal`) is a real audience with its own pages.

## D2. The party on the case

`case.portalParty` (string, `visible: false` on staff forms, projected on `mijnZaken`):

| Who filed | `portalParty` |
| --- | --- |
| a `client` or `citizen` session for themselves | `subject:<subjectRef>` |
| a `business` or `supplier` session | `kvk:<identityRef>` (the KVK number) |
| any session acting under a mandate | the mandate's `onBehalfOf`, in the same typed form |

`mijnZaken` declares `mandateField: 'portalParty'`. Portaliq then lists, for a session with an
active mandate, the cases whose `portalParty` equals the mandate's `onBehalfOf`, filtered by
the mandate's `caseTypes` through the existing `caseTypeField`. A company session without a
mandate still reads its own cases through `scopeField: portalSubject`; to see the whole
company's cases it needs a company mandate, which is portaliq's to grant.

Why a typed string rather than two fields: `mandateField` is one field. A company and a person
can both be represented, so the field must hold either, and the prefix keeps a KVK number from
ever matching a subject reference by accident.

The represented party is never a raw BSN. A person is named by the one-way `subjectRef`
portaliq derives, as `portalSubject` is today.

## D3. The branch

Unchanged from `portal-case-list-declarations` D3: `portalBranch` on the case, copied from the
session by `IntakeFanOut` and `WooRequestIntake`, and `branchField: 'portalBranch'` on
`mijnZaken`. This change lands it for `business` as well as `supplier`.

## D4. Who acted

`CitizenWriteRecorder` writes `{action, audience, minTrust, actingFor, mandate}` into
`portalWrites` on the case. `caseTimeline` reads that record: an entry for a portal write with
`actingFor` reads "{display name}, namens {party label}". The display name comes from the
portal write record; the party label from the mandate's `label`. When either is missing the
entry reads "Namens {party}" or the plain entry of today. The represented person, opening the
same case, sees the same entry.

## D5. The phone layout

`DossiqPhone.dc.html` is the same pages on a narrow screen: the acting-for bar on top, the
open question, a short menu (Zaken, Berichten, Iets nieuws aanvragen, Bel de gemeente), the
case cards, and "Uw machtiging". Dossiq declares nothing phone-specific. The menu entries are
portaliq's shell sections and the CMS menu; "Uw machtiging" is portaliq's mandate detail.

## Risks

- **A wrong match shows a stranger's case.** The typed prefix and the equality match make that
  impossible across kinds. Within a kind it is as safe as the `onBehalfOf` portaliq grants.
- **Cases filed before this change have no party.** They stay visible to the person who filed
  them through `portalSubject` and invisible to a mandate holder, which is the safe default.
  A one-off backfill can set `portalParty` from `portalSubject`; it is listed as a task.
