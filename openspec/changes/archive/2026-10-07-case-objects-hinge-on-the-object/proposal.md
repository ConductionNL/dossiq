---
kind: code
depends_on: []
---

# Proposal: case-objects-hinge-on-the-object

Parity ledger row 2.15, "custom objects linked to cases", cluster 5. The
ledger rates dossiq partial on the row and gives the reason as "the
`caseObject` schema only, no page and no widget". The consumer half of
openregister `objects-as-the-hinge-between-cases`, merged as
openregister#3765 (`2f271e1fa`). The contract dossiq builds against is
the reverse view, the lens, the declared list surface, inherited map
features and the `intake-sources` register.

## Why

A case is about something. A building, a parcel, a vehicle, a tree. The
thing outlives the case: one building collects a vergunning, two
handhavingszaken and a bezwaar over ten years, and the question a
handler asks at the counter is not "what is on this case" but "what is
on this address".

Dossiq answers the first question and not the second. It also answers
the first one by copying: whoever links an object types the object's
name into the link row, and from that moment the register and the case
carry two names that drift apart. Nothing tells anyone which one is
current.

## What is actually there

Read against `development` at `50788fd9`. **The ledger's reason for
rating this row partial is stale, and the correction is the useful
part.**

`custom-objects-on-the-case` shipped and is archived
(`openspec/changes/archive/2026-09-08-custom-objects-on-the-case/`).
The tree carries all three of the things the ledger says are missing:

- the `caseObject` schema, `lib/Settings/dossiq_register.json`, with
  `case`, `objectType`, `objectIdentification`, `objectUrl` and
  `description`;
- the widget: `case-objects`, an `object-list` over `caseObject` filtered
  on the open case, a section of the Related tab on `CaseDetail`
  (`src/manifest.json`), asserted by `tests/vitest/caseObjects.spec.js`;
- the page: `CaseObjects`, an index at `/case-objects` with a folder
  sidebar on `objectType`, whose rows open the case rather than the link.

So the gap is not the surface. It is what the surface can say. Three
things are missing and each of them is now a declaration rather than
code:

1. **The link row carries a copy.** Whatever the object is called sits
   in `objectIdentification`, typed once. The lens resolves it on every
   read instead.
2. **The object's own page cannot name its cases.** OpenRegister's
   `/referenced-by` groups the records that reference an object and
   titles each row with that record's stored name. A `caseObject`
   declares no `objectNameField`, so the group on a building's page is a
   list of rows with no names in it.
3. **A case about an address does not know where it is.** `case-location`
   carries a latitude and a longitude somebody filled in. The BAG object
   the case hinges on already holds the point, and the parcel holds an
   outline; copying either makes two shapes that disagree the moment the
   parcel is redrawn.

## What changes

- `caseObject` declares two lenses onto the object it names: its title
  and its status, resolved by OpenRegister at read time, never stored.
- The Objects tab shows both. A value the reader may not see renders as
  withheld, in words, and never as an empty cell.
- `caseObject` declares its list surface, so a generic list over the
  schema shows the columns dossiq would show and searches what dossiq
  would search, with no page written for it.
- A `caseObject` row is named after the case it belongs to, so the
  Referenced by tab on the linked object's own page reads as a list of
  cases.
- `case-location` declares the geometry it inherits from the linked
  object. The case's map shows the object's address point or parcel
  outline marked as inherited, naming the relation it came through, and
  a location that carries its own geometry outranks it.
- The five channels a case arrives through, mail, the portal, the API,
  the contact centre and the DSO, become objects in OpenRegister's
  `intake-sources` register. Every one ships switched off.

## Ownership

Everything mechanical here belongs to openregister and is already
merged. dossiq declares and renders:

| what | who |
|---|---|
| the lens resolver, the withheld marker, the write refusal | openregister, `LensResolver` |
| `/referenced-by`, its grouping and its access | openregister, `ReferencedByService` |
| `/schemas/{id}/list-presentation` | openregister, `ListPresentationResolver` |
| `/geo-features`, `_source`, `_through`, `_superseded` | openregister, `InheritedGeoCollector` |
| the `intake-sources` register and its schema | openregister, `SeedIntakeSourceRegister` |
| which lenses, which columns, which geometry, which channels | dossiq, here |

## ADRs

- Company ADR-022: an app consumes OpenRegister's abstractions rather
  than rebuilding them. Every requirement below is a declaration on a
  schema or a column on a surface, and dossiq writes no reverse lookup,
  no lens resolver and no geometry collector of its own.
- Company ADR-060: a test that cannot fail is phantom green. A
  declaration nothing reads is the same shape, so each requirement names
  its reader.
- dossiq `openspec/specs/case-management/spec.md` is what this extends.

## Capabilities

- Modified: `case-management`: the case's objects carry the linked
  object's own title and status, the object's page names its cases, a
  case inherits the object's geometry with its provenance, and the
  channels a case arrives through are objects.

## Impact

`lib/Settings/dossiq_register.json` (`caseObject` and `case-location`),
`lib/Settings/intake_sources.json`, `lib/Service/Intake/IntakeSourceSeeder.php`,
`lib/Repair/SeedIntakeSources.php`, `appinfo/info.xml`,
`src/manifest.json` (the `case-objects` widget), `src/utils/lensValue.js`,
`src/components/cells/LensedValueCell.vue`, `src/services/cellWidgets.js`,
the Dutch and English catalogues.

## Where this change disagrees with the ledger

**Row 2.15's reason is out of date.** The page and the widget both
exist and both are tested. Sized accordingly: this is a set of
declarations on two schemas plus one cell component, not a new surface.

## What this change does not do

- **It does not make the Referenced by tab list `case` records.** The
  reference is held by the link row, so the group on an object's page is
  a group of `caseObject` rows and always will be, until a group can
  follow a link row to its parent. Naming each row after its case is the
  half that is dossiq's, and it is what makes the list readable. The
  other half is openregister's and is recorded under "Needs a change in
  openregister" below.
- **It does not rebuild that tab.** It already exists
  (`src/components/object-relations/ReferencedByTab.vue` in openregister)
  and it is verified here, not replaced.
- **It does not resolve a title for an object type that names its title
  something other than `name`.** A lens reads one property path. A
  schema whose display field is `title` or `omschrijving` shows an empty
  Object column, which reads as "no title" and not as withheld. Recorded
  below.

### Needs a change in openregister

Two, neither of them blocking:

1. A reverse-view group could follow a link row to the record it points
   at, so an object's page can list the cases rather than the links. The
   contract names the inverse label as `relation-types-with-inverses`
   work; this is the sibling of it on the row rather than the label.
2. A lens could fall back to the referenced schema's own
   `objectNameField` when its `property` resolves to nothing, so "the
   linked object's title" is one declaration rather than one per target
   schema.

## Out of scope

- The layout per case type. CT-6, and buildiq's under D16.
- The inverse label on a relation. openregister
  `relation-types-with-inverses`, a sibling lane; until it lands a
  reverse-view group is named by its schema title.
- Polling the channels. `enabled` is the switch and the pollers belong to
  integriq and to `inbound-mail-filters`; this declares the sources.
