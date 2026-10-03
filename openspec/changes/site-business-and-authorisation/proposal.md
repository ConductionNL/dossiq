---
kind: code
depends_on: [portal-case-list-declarations, site-resident-portal-design]
---

# Proposal: site-business-and-authorisation

Part of the portal-design programme (2026-10-02). Ruben decided that dossiq serves three
audiences: residents (DigiD), businesses (eHerkenning) and people acting for someone else
(machtigen). He approved `DossiqBusiness.dc.html` (Jan-Willem van der Berg for Slagerij Van der
Berg, KVK 12345678, with the "Voor wie regelt u nu zaken?" switcher and the list of people
authorised for the company) and `DossiqPhone.dc.html` (Linda Bakker acting for her father,
H. Bakker, on a phone).

## Why

A business that signs in sees nothing of its cases, and a person acting for someone else sees
nothing of theirs. Reading both repos on 2026-10-02 shows why, and shows that most of the
machinery is portaliq's and already exists.

**What portaliq already owns** (portaliq `development` `b150def5`; this change does not
respecify any of it):

- **Login to audience.** `OidcClaimMapperService` presets map DigiD and eIDAS to `client` and
  eHerkenning to `supplier`. Each organisation can override the audience per login
  (`claimMap.audience`, a fixed value or `claim:<name>`). The KVK number arrives as the
  account's `identityRef`.
- **Branch scope.** `portal-branch-scope` REQ-SEB-001 to 003: an eHerkenning login for one
  branch is restricted to it; a whole-company login can narrow to a branch with the
  `BranchSwitcher`; a collection without `branchField` shows nothing to a restricted session.
- **Mandates.** The `portalMandate` schema: holder `subjectRef`, `onBehalfOf` (the represented
  party, for example a KVK number), `label`, `caseTypes`, `reach`, `status`, `grantedBy`,
  `grantedAt`, `expiresAt`. `portal-identity-and-the-organisations-cases` REQ-PIOC-002 and 008:
  an identity sees the cases its mandates cover and switches the active mandate in the session.
  `portal-my-cases` REQ-CMC-003 and 004: a case seen through a mandate says why, and the header
  offers "for whom" (`ActingForSwitcher`). `portal-visibility-and-the-party-tree` REQ-PTV-001
  to 006: a mandate can reach down a party tree.
- **Mandate scoping of case lists.** `PortalCaseListReader` matches a `kind: cases` collection's
  `mandateField` against the mandate's `onBehalfOf`, filters by `caseTypes` through
  `caseTypeField`, and skips a collection without `mandateField`.
- **Access requests and invitations.** `portal-access-requests` REQ-IAR-001 to 003 ("Toegang
  tot zaken": ask, staff grant or refuse, a grant writes a mandate) and
  `portal-account-administration` REQ-ISA-001 to 004 (staff invite and withdraw).
- **The write record.** On a portal write under a mandate, portaliq records `actingFor` and
  `mandate` on the case's `portalWrites` (`CitizenWriteRecorder`).

**What portaliq adds in its own change** (not specified here): portaliq
`site-mandates-the-represented-manage`, on portaliq `development` since portaliq#1123. It gives
the represented party the "Wie mag zaken regelen voor uw bedrijf?" list with scope and end
date, "Iemand machtigen" by email invitation, "Intrekken" and "Uitnodiging intrekken" of
`DossiqBusiness.dc.html`, and the holder's "Machtiging stoppen" of `DossiqPhone.dc.html`.
Ruben decided on 3 October 2026, recorded there as D7 and REQ-SMR-001 to 006:

- `onBehalfOf` and the new `holder` are typed: `kvk:` plus 8 digits, or `subject:` plus a
  subject reference (REQ-SMR-001).
- **A company holds the mandate it accepts** (`holder: kvk:<n>`), so every eHerkenning sign-in
  for that KVK number carries it; a person holds their own (`subject:<subjectRef>`)
  (REQ-SMR-005). Portaliq keeps its own mandate record; it does not use Open Cloud Mesh.
- **A private person may authorise** someone, by email invitation, after signing in once with
  DigiD; the represented party is then `subject:<their subjectRef>`.
- **Known blocker, portaliq task T0:** an eHerkenning session does not yet reliably carry the
  company's KVK number. Portaliq maps one identity claim; T0 adds `claimMap.kvk` beside a
  per-person `identityRef` and carries it on the session as `kvk`.

**What is missing in dossiq**, which this change adds:

- **No business audience.** An eHerkenning session arrives as `supplier`, and dossiq answers
  `supplier` with the procurement manifest: tenders, contracts, invoices, performance. A
  butcher's shop would see four empty procurement pages and none of its permit cases.
  `portal-case-list-declarations` (REQ-PORTAL-009) already decided to add the case collection
  to the supplier manifest; its task 1.1 is open.
- **No party on the case.** `mijnZaken` is scoped by `portalSubject`, the person who filed.
  A case filed by an employee of the slagerij is that employee's, not the company's. Nothing on
  the case names the company or the represented person in a form a mandate can match, so
  `mijnZaken` declares no `mandateField` and portaliq skips it for every mandate.
- **No branch on the case.** `portal-case-list-declarations` REQ-PORTAL-011 specifies
  `portalBranch` and `branchField`; tasks 3.1 and 3.2 are open.
- **The timeline does not say who acted.** "Hij ziet alles wat u hier doet" needs the case
  history to name Linda as acting for H. Bakker. Portaliq records it on the write; dossiq's
  `caseTimeline` does not show it.

## What changes

- **New: a `business` audience.** `getAudiences()` adds `business`; `getContribution()`
  answers it with the resident manifest (cases, messages, questions, the create actions), with
  the case collection scoped by the company. A municipality portal maps eHerkenning to
  `business` with portaliq's existing per-organisation `claimMap.audience`; no portaliq code
  changes for that. `supplier` keeps the procurement manifest and, as
  `portal-case-list-declarations` decided, the case collection.
- **New: the party a case belongs to.** A case property `portalParty`, in portaliq's typed
  form (REQ-SMR-001): `kvk:<8 digits>` for a company case, `subject:<subjectRef>` for a
  person's own case. It is written by every path that opens a case from a portal write, from
  the session that wrote it: the session's `kvk` for a `business` or `supplier` session (after
  portaliq T0), the subject of a `client` session, or the represented party when the write ran
  under a mandate. `mijnZaken` declares `mandateField: 'portalParty'`.
- **Builds on `portal-case-list-declarations`:** lands its open halves for the new audience
  too: `portalBranch` and `branchField` (REQ-PORTAL-011), and the case collection for
  `supplier` (REQ-PORTAL-009).
- **Who acted, on the timeline.** A `caseTimeline` entry for a portal write made under a
  mandate names the person and the party: "Linda Bakker, namens H. Bakker".
- **Page declarations** for the business overview (`DossiqBusiness.dc.html`): the same blocks
  as the resident overview, with "Lopende zaken van uw bedrijf" as the case heading, and
  `menu: false` on every page. The "for whom" switcher, the acting-for bar and the mandate list
  are portaliq's shell; dossiq declares no block for them.

## Depends on portaliq

- `site-mandates-the-represented-manage` (portaliq `development`): typed parties, the company
  as holder, invitations, revocation, expiry and the screens. Written; not yet built.
- **Its task T0, the KVK number on an eHerkenning session.** Until T0 lands, a business session
  has no reliable KVK number. Dossiq then writes `subject:<subjectRef>` for that session's
  case, so no company mandate can reach it. That is the safe failure: the person who filed
  still sees the case, and nobody else does.
- **Two asks of portaliq that its mandate change does not yet state** (tasks 0.1):
  1. A write or endpoint call made by a session hands dossiq the party: the session's `kvk`,
     or the mandate's `onBehalfOf` when it acts under one. For a flat create portaliq stamps
     it into `mandateField`, as it stamps `branchField`; for an endpoint action
     (`startWooVerzoek`) the `X-Portal-Subject` assertion carries it.
  2. A business session sees the cases of its own company, not only the ones its own person
     filed: "Zaken" also matches `mandateField` against the session's own `kvk:` party.

## What this change does not do

- It does not build the mandate list, invite, revoke, the switcher or the acting-for bar.
  Portaliq owns `portalMandate` and its screens (`site-mandates-the-represented-manage`).
- It does not connect to DigiD Machtigen or eHerkenning ketenmachtiging. A mandate comes from
  portaliq, whatever its source.
- It does not let a mandate scope anything but the case list and the case page. Messages and
  questions stay scoped to the person, as today.

## Decided, and still open

- **Decided (Ruben, 3 October 2026):** typed parties `kvk:` and `subject:`; a company holds
  the mandate it accepts; a private person may authorise by invitation; portaliq keeps its own
  mandate record. This change follows all four.
- **Settled by the invitation route:** a person who never signed in cannot be named, so
  portaliq never looks one up; the invitee accepts after their own sign-in, and the
  represented person is the one who sent the invitation (`DossiqPhone.dc.html`).
- **Still open:** a scope finer than case types. "Mag alleen bezwaren indienen en volgen" fits
  `portalMandate.caseTypes`. "Zaken bekijken, aanvullen en nieuwe aanvragen doen" is a list of
  acts, which `portalMandate` cannot express.

## Capabilities

- Modified: `portal-contribution`.

## Impact

`lib/Portal/PortalContributionProvider.php` (audience, collection keys, pages), the case schema
(`portalParty`), the two paths that open a case from a portal write (`WooRequestIntake` and
`lib/Service/Intake/IntakeFanOut.php`), `lib/Controller/PortalWooRequestController.php` (`AUDIENCES`),
`lib/Portal/PortalAssertionVerifier.php` callers, the timeline reader, `l10n/`.
