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

**What is missing in portaliq** (not specified here; proposed as the separate portaliq change
`site-mandates-the-represented-manage`, which is NOT YET WRITTEN and is not part of portaliq#1110): no screen lists a
party's authorised people with scope and end date; a company or a person cannot invite someone
to act for them; there is no revoke in the portal; a grant always writes `reach: organisation`
with no expiry. These are the "Wie mag zaken regelen voor uw bedrijf?" list, "Iemand machtigen"
and "Intrekken" of `DossiqBusiness.dc.html`, and "Machtiging stoppen" of `DossiqPhone.dc.html`.

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
- **New: the party a case belongs to.** A case property `portalParty`, a typed reference:
  `kvk:<number>` for a company case, `subject:<subjectRef>` for a person's own case. It is
  written by every path that opens a case from a portal write, from the session that wrote it
  (the KVK number of a `business` or `supplier` session, the subject of a `client` session, or
  the represented party when the write ran under a mandate). `mijnZaken` declares
  `mandateField: 'portalParty'`.
- **Builds on `portal-case-list-declarations`:** lands its open halves for the new audience
  too: `portalBranch` and `branchField` (REQ-PORTAL-011), and the case collection for
  `supplier` (REQ-PORTAL-009).
- **Who acted, on the timeline.** A `caseTimeline` entry for a portal write made under a
  mandate names the person and the party: "Linda Bakker, namens H. Bakker".
- **Page declarations** for the business overview (`DossiqBusiness.dc.html`): the same blocks
  as the resident overview, with "Lopende zaken van uw bedrijf" as the case heading, and
  `menu: false` on every page. The "for whom" switcher, the acting-for bar and the mandate list
  are portaliq's shell; dossiq declares no block for them.

## Depends on a portaliq change that does not exist yet

The mandate list with scope and end date, invite, revoke, expiry and the typed `onBehalfOf`
are proposed as portaliq `site-mandates-the-represented-manage`. That change is NOT YET WRITTEN. portaliq#1110
(`site-mijn-omgeving-components`, `site-multi-step-forms`, `site-nlds-widget-palette`) does not
cover mandate management. Until it lands, the dossiq half here works with the mandates
portaliq can already make (an access request granted by staff, REQ-IAR-003): no expiry, no
portal revoke, untyped `onBehalfOf`.

## What this change does not do

- It does not build the mandate list, invite, revoke, the switcher or the acting-for bar.
  Portaliq owns `portalMandate` and its screens (list above, for `site-mandates-the-represented-manage`, not yet
  written).
- It does not connect to DigiD Machtigen or eHerkenning ketenmachtiging. A mandate comes from
  portaliq, whatever its source.
- It does not let a mandate scope anything but the case list and the case page. Messages and
  questions stay scoped to the person, as today.

## Open decisions

- **The typed party reference.** Portaliq stores a free-text `onBehalfOf`, "for example a KVK
  number". For `mandateField` to match, both apps must write the same form. This change
  proposes `kvk:<number>` and `subject:<subjectRef>`; portaliq must agree in
  `site-mandates-the-represented-manage` (not yet written).
- **How a person-for-person mandate names the represented person.** Portaliq holds no BSN. The
  represented person's `subjectRef` is derived one-way from their login. A mandate made before
  they ever signed in cannot name it. This is portaliq's to solve; until then
  `DossiqPhone.dc.html` works only for a represented person who has signed in once.
- **Scope finer than case types.** "Mag alleen bezwaren indienen en volgen" fits
  `portalMandate.caseTypes` (the bezwaar case types). "Zaken bekijken, aanvullen en nieuwe
  aanvragen doen" is a list of acts, which `portalMandate` cannot express today.

## Capabilities

- Modified: `portal-contribution`.

## Impact

`lib/Portal/PortalContributionProvider.php` (audience, collection keys, pages), the case schema
(`portalParty`), the two paths that open a case from a portal write (`WooRequestIntake` and
`lib/Service/Intake/IntakeFanOut.php`), `lib/Controller/PortalWooRequestController.php` (`AUDIENCES`),
`lib/Portal/PortalAssertionVerifier.php` callers, the timeline reader, `l10n/`.
