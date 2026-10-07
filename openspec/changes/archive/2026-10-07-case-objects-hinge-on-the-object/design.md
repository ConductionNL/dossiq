# Design: case-objects-hinge-on-the-object

Five decisions. Each one is a place where the obvious build and the
declarative build differ, and the declarative one wins because the
mechanism is already merged in openregister.

## D-1 · The object's title is a lens, not a column

**Decision.** `caseObject` declares `x-openregister-lenses` with
`objectTitle` and `objectStatus`, both looking through `objectUrl`.
Neither is a stored property and nothing writes them.

**Why not a column.** A stored copy is right exactly once, on the day
somebody typed it. A vergunning that has been through two owners and a
renumbering carries the old address on the case and the new one in the
register, and no screen says which is which.

**What it costs.** A lens reads one property path on the referenced
record. `objectTitle` reads `name`, which is openregister's usual
display field, so an object type whose display field is `title` shows an
empty Object column. Empty is honest here, because the withheld marker is
what distinguishes "may not see it" from "nothing there", and this is the
"nothing there" case. The fallback belongs upstream and is recorded in
the proposal.

**`through` is `objectUrl` and not a `$ref`.** The lens takes a bare
uuid, a uri ending in one, or an extended object. `objectUrl` holds the
uri, which is what a link to a record in another register looks like.

## D-2 · Withheld is a component, not a formatter

**Decision.** A lens column renders through `lensedValue`, a cell widget
in `src/services/cellWidgets.js`, reading the marker through
`src/utils/lensValue.js`.

**Why not a formatter.** A formatter returns a string, and the withheld
marker is an object: `{ "@withheld": true, "reason": "access" }`. It is
truthy, so a `value ? … : dash` shows it, and it stringifies to
`[object Object]`. Both failures are silent, and a blank cell is worse
than either, because blank states that the case is about nothing. Three
states, three shapes: a padlock and the word, an em dash, or the value.

**Why not the library.** `CnObjectListWidget` already forwards `widget`
and `widgetProps` to `CnCellRenderer`, which resolves the name against
the app's own `cnCellWidgets`. Nothing in nextcloud-vue changes.

**Why the tab stays an `object-list`.** The lane brief calls for an
`object-table`. The tab is an `object-list` today, tested, with its
facet chips and its empty state, and both widgets forward a column's
`widget` to the same cell renderer. Swapping the type would rewrite the
content shape to gain nothing this change needs, so the type is left
alone and the columns are what change.

## D-3 · A link row is named after its case, through the relation itself

**Decision.** `caseObject` declares
`objectNameField: "case | objectIdentification | objectType"`.

**Why.** `/referenced-by` titles every row with the referencing record's
stored name. Without a name field a `caseObject` group on a building's
page is a list of rows with nothing in them. With one, the group answers
"which cases is this building in" by name.

**Why the relation and not a mirror of it.** The first version of this
declaration pointed at `caseTitle`, a materialised calculation reading
`@ref.case.title`. It would have shipped a reverse view of unnamed rows.
`SaveObject::hydrateObjectMetadata()` writes the name, and it runs before
`insertObjectEntity()` dispatches `ObjectCreatingEvent`, which is where
`CalculationOnSaveListener` materialises a calculation. So the mirror is
empty on exactly the save that names the row, and the name only arrives on
the next edit. Nothing would have reported that: the row would simply have
had no name.

Resolving the relation needs no second save and no mirror.
`MetadataHydrationHandler::resolveRelationValue()` sees that `case` carries
a `$ref`, takes the uuid out of it, and asks the name cache for the
referenced record's own name. One save, one hop, no stored copy.

**Why a chain.** A name field that resolves to nothing falls through to
the handler's common-field list, `naam`, `name`, `title`, `label`,
`titel`, and a `caseObject` carries none of them, so the row would be
unnamed. The chain ends on `objectType`, which the schema requires, so it
always lands.

## D-4 · Geometry is inherited, with its provenance, and never copied

**Decision.** `case-location` gains `linkedObject`, a uri, and declares
`x-openregister-geo-inheritance` reading through it.

**Why on `case-location` and not on `case`.** A case has 0..N locations
and the geometry belongs to a location, not to the case. A case about two
addresses inherits two geometries, each one naming the object it came
from.

**Why one hop.** The collector reads the referenced record's own features
and does not recurse. So `linkedObject` points at the object itself, the
BAG pand or the parcel, not at the `caseObject` link row, whose own
geometry is empty. A two-hop declaration would return nothing and say
nothing about why.

**Provenance is the point.** Every inherited feature comes back with
`_source: inherited`, `_through`, `_fromObject` and the declared label. A
location that holds its own geometry for the same purpose wins, and the
inherited one is marked `_superseded` rather than dropped, because "where
did the other pin go" is a question somebody asks out loud.

## D-5 · A channel is an object, switched off

**Decision.** The five channels are rows in openregister's
`intake-sources` register, seeded by a repair step that upserts on
`slug`, writes `enabled: false` on create, and never writes `enabled`,
`connection`, `location`, `state` or `settings` again.

**Why the switch is never rewritten.** Those five are what an
administrator set. An upgrade that re-enabled a source would start
polling a mailbox nobody asked it to poll, and it would do so silently.

**Why `kind` is not the channel.** The schema's `kind` takes
`watchedFolder`, `mailbox` or `endpoint`: it is the transport. A portal,
an API caller, a contact centre desk and a DSO message all arrive over
HTTP, so all four are `endpoint` and the `slug` tells them apart.

**Why the register's absence is not an error.** The register belongs to
openregister and is seeded by its own repair step. A deployment where
that step has not run yet warns, writes nothing, and seeds on the next
upgrade. A repair step that threw would abort the upgrade instead.
