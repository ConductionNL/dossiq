# Design: contacts-domain

## Context

`brpPerson` (`lib/Settings/register.d/25-brp-kvk.json`, register `dossiq`,
version 1.0.0) carries `citizenServiceNumber` (format `bsn`), `name`, `birth`,
`residence`, `displayName` and `description`; `citizenServiceNumber` and
`name` are required. `kvkCompany` (same file, register `dossiq`) carries
`kvkNumber`, `tradeName`, `legalForm`, `address` and `description`.
`requester-on-the-case` makes both implement `ns#Requester`, so
`case.requester` is the uuid of a row in one of them, and `case` keeps the
projection `initiatorType`, `initiatorSourceId` and `initiatorDisplayName`.

`contactmoment` (`register.d/40-kcc-werkplek.json`, register `dossiq`,
version 1.1.0) carries channel, direction, start and end time, summary,
`kccEmployeeId`, `relatedCases` and `geidentificeerdeBurgerId`, a plain
string with a Dutch name. It has no reference to a register row.
`customerContact` in `dossiq_register.json` (`case`, `contactDateTime`,
`channel`, `subject`, `initiator`) is the older case-side moment; A17 chose
`contactmoment`, and this change follows.

The navigation (`src/manifest.json` `menu`, then `src/menu-layout.json`
relocations) shows Dashboard (order 10) and the My work group (order 20,
holding Queue, Assigned to me, All cases, Tasks and Workflow board) at the top
level, Reports, Documentation, Store and Features in the footer, and the
admin entries in the gear foldout. `tests/e2e/navigation.spec.ts` asserts
that Dashboard is the only top-level leaf.

nextcloud-vue 2.40.0 `CnIndexPage` takes one `schema` and an optional
`folderSidebar` whose `source` is `register`, `field`, `custom` or `files`;
picking a folder filters the page's schema by `filterField`. Nothing in the
vocabulary lets a folder swap the schema. `type: detail` pages take one
schema. `object-list` widgets resolve `@objectId` in `filter`, and
`open-form` header actions prefill through `props`, as `log-hours` does.

ADR-032 kind: **config**. A menu entry, three pages, a schema property and
column edits. The two things that would need code (a per-folder schema and a
column link chosen by a sibling field) are named as nextcloud-vue work and
blocked, not written here.

## Goals / Non-goals

**Goals:**

- You find a person or an organisation by name or number and open them.
- You see their cases and their contact moments in one place.
- You file a case for them, or log a call, from their page.
- Contacts sits at the top level as a domain, and nothing else joins it.

**Non-goals:**

- Live BRP or KvK lookups and subscriptions (B22, Tier B, integriq).
- The KCC panel with caller context, routing and callbacks (B20, Tier B).
- A timeline or communication panel over the contacts leaf (B01,
  OpenRegister).
- Editing a person's BRP data. The register set is read from the source; the
  data card is read only.
- The Parties tab on the case (`parties-on-the-case`) and the requester
  picker (`requester-on-the-case`). This change links to what they build.

## Decisions

### D1: one index over `brpPerson`, a folderSidebar with two folders, and the honest limit

Three ways to make one page list people and organisations:

1. A `folderSidebar` with a People folder over `brpPerson` and an
   Organisations folder over `kvkCompany`. Not possible today: a folder
   filters the page's one schema, it cannot change it.
2. A `party` view schema in dossiq that copies both into one shape. A third
   copy of the same person, a write on every pick, and a `$ref` that
   contradicts `requester-on-the-case` D1, which keeps `requester` a uuid
   into the register sets. Rejected.
3. Two index pages under a Contacts group. Two entries where one was asked,
   and a group for two leaves.

Option 1 ships, with its limit written down. Page `Contacts` (route
`/contacts`, type `index`, register `dossiq`, schema `brpPerson`, columns
`displayName` (Name), `citizenServiceNumber` (Number), `residence.address`
(Address), `description`; `sidebar.enabled`, `allowSavedViews`,
`showViewAction: false`, a `view` action to `ContactDetail`) carries
`folderSidebar: {source: custom, filterField: "@self.schema", allLabel: "All
contacts", folders: [{id: brpPerson, name: People, schema: brpPerson}, {id:
kvkCompany, name: Organisations, schema: kvkCompany, columns: [...]}]}`. The
`schema` and `columns` keys on a folder are the request to nextcloud-vue: a
folder that swaps the index's schema and column set. B10 (columns per
folderSidebar scope) asks for the same seam. Until it lands the
Organisations folder is `hidden: true`, the index lists people, and an
organisation is reached from its case through the initiator card. The e2e
asserts the People folder and the hidden state, not an organisation row.

The search box of `CnIndexPage` covers `displayName` and
`citizenServiceNumber`, which is the OpenCase and Zaaksysteem entry point
(number or name). Address search waits on the same folder work.

### D2: two detail pages, one per schema, same anatomy

A detail page takes one schema, so `ContactDetail` (route `/contacts/:id`,
schema `brpPerson`) and `OrganisationDetail` (route `/organisations/:id`,
schema `kvkCompany`) both ship. Neither is a menu entry; detail pages spend
no nav budget and are not custom pages (ADR-100). Widgets, by id:

| widget | type | content |
|---|---|---|
| `contact-card` | `data` | `include` the identity fields; `editable: false` |
| `contact-cases` | `object-list` | `case` where `requester = @objectId`, columns `identifier`, `title`, `status`, `deadline`, sort `deadline` asc, `rowRoute: CaseDetail`, `viewAllRoute: Cases`, `viewAllQuery: {requester: @objectId}`, `emptyText: "No cases for this contact yet"` |
| `contact-moments` | `object-list` | `contactmoment` where `contact = @objectId`, columns `startTime`, `notificationChannel`, `direction`, `summary`, sort `startTime` desc, `emptyText: "No contact moments yet"` |

The sidebar carries the audit tab only (`{"type": "audit"}`), which is the
timeline Zaaksysteem shows. On `ContactDetail` the card masks
`citizenServiceNumber` for a protected person the way `requester-on-the-case`
D4 does; the same override applies, nothing new.

### D3: header actions prefill through `props`, as `log-hours` does

`new-case-for-contact`: `open-form`, register `dossiq`, schema `case`,
`props: {"requester": "@objectId"}`, label New case, icon `FolderPlusOutline`,
`successMessage: "Case filed for this contact."`. The form is the same one
`Dashboard` opens: `requester` renders through `InitiatorPicker`
(`requester-on-the-case` D2) and arrives filled in, and the picker writes the
projection fields on save. On `OrganisationDetail` the same action carries
the organisation's uuid.

`log-contact`: `open-form`, register `dossiq`, schema `contactmoment`,
`props: {"contact": "@objectId"}`, label Log contact, icon
`PhoneLogOutline`, `successMessage: "Contact moment logged."`. `includeFields`
limits the form to `notificationChannel`, `direction`, `startTime`, `nature`,
`summary` and `relatedCases`; the rest of the schema is KCC bookkeeping.
Both actions carry no `visibleWhen`; the schemas ship with dossiq.

### D4: `contactmoment.contact` is a uuid with the requester's semantic type

`{"type": "string", "format": "uuid", "referenceSemanticType":
"https://openregister.app/ns#Requester", "title": "Contact", "facetable":
true}`, optional, in `register.d/40-kcc-werkplek.json` and
`dossiq_mock_register.json`, version 1.1.0 to 1.2.0. The semantic type is
the one `case.requester` carries, so `InitiatorPicker` serves both fields
once its `appliesTo` gains `contactmoment.contact`, and a person or an
organisation can be the contact without a second field. The name is English
(D13). `geidentificeerdeBurgerId` stays as it is for the KCC bridge; a later
change may migrate it.

### D5: the requester links to the contact from the card, and the column waits

`initiator-display` says the identifying number links to the source record.
The link target becomes `ContactDetail` for a person and `OrganisationDetail`
for a company; `InitiatorSection` already resolves `initiatorType`, so this
is a route id in the component's existing link, one line, and stays inside
`requester-on-the-case`'s code. The Requester column on `Cases`
(`initiatorDisplayName`, `requester-on-the-case` D5) becomes a link when a
column can name a route and a param and pick the route from a sibling field.
That is nextcloud-vue work and is blocked; until then the column is text and
the row opens the case, where the card links onward.

### D6: one entry, order 25, and the guards that keep it alone

Menu entry `{"id": "Contacts", "label": "Contacts", "icon":
"AccountGroupOutline", "route": "Contacts", "order": 25}`. `menu-layout.json`
does not relocate or remove it. The top level becomes Dashboard, My work,
Contacts: three of the six ADR-097 allows. `navigation.spec.ts` asserts the
top-level leaves; it gains Contacts. No page joins the domain in this change:
the KCC panel and the subscriptions are blocked tasks, and if either arrives
it is a widget on the contact page, not an entry.

## Declarative-vs-imperative decision (ADR-031)

| behaviour | path | reason |
|---|---|---|
| Listing people | declarative, `index` over `brpPerson` | The same shape as `CaseTypes`. |
| Listing organisations beside them | declarative, a folder with a `schema` | Waits on nextcloud-vue; hidden until then. |
| A person's cases and moments | declarative, `object-list` with `@objectId` | The same shape as `case-tasks`. |
| New case, Log contact | declarative, `open-form` with `props` | The platform writes the row. |
| Linking the requester to the contact | a route id in `InitiatorSection` | Already code in `requester-on-the-case`. |
| Masking a protected number | declarative, the D4 override of `requester-on-the-case` | Reused, not rewritten. |

## Seed data

`25-brp-kvk.json` already seeds personas and companies. The e2e seeds its own
`brpPerson` row, one `case` with `requester` set to it, and one
`contactmoment` with `contact` set to it, through `createObject` in
`tests/e2e/helpers/fixtures.ts`, and removes them with `cleanupRunObjects`.
No demo data changes.

## Risks / Trade-offs

- The index lists people only until the folder can swap schema. The label
  says Contacts and the People folder is selected by default, so the page
  tells you what it holds. Accepted: the alternative was a third copy of the
  data.
- `brpPerson` is a test register set, seeded from the BRP mock. On a real
  instance it holds whoever integriq's adapter writes into it (B22). The
  page is honest about that in its `description`.
- `case.requester` is a uuid; `contact-cases` filters on it exactly. A case
  whose requester is a Nextcloud contact has an empty `requester` and does
  not appear. That is the gap `requester-on-the-case` D1 accepts.
- A `logReads` flag is not set on `brpPerson`; opening a contact page is not
  a logged processing event. `verwerkingsactiviteiten.json` names the BRP
  read; whether every page open should log is a question for the AVG
  register, not this change.
- Two detail pages share their widget ids. Widget ids are page-scoped in
  the manifest; `npm run check:manifest` confirms.
