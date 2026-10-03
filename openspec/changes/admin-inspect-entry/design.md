# Design: admin-inspect-entry

## D-1. Two flat actions, not one with children

The first draft of this design named one `case-inspect` action carrying two
`children`. That does not render.

`CnDetailPage` runs `CnActionButtons` in `display: "menu"`, and that mode draws
no buttons of its own: it emits `menuEntries`, which maps the visible actions
into menu descriptors and never looks at `children`. The chevron the `children`
key documents belongs to the inline `barActions` mode, which a detail page
never uses. A parent with two children would have shown one menu item and
neither entry, and warned nobody.

So `#CaseDetail` carries two header actions:

- `case-inspect-raw`, `open-modal` on `CaseRawDataDialog`
- `case-inspect-runs`, `navigate` to
  `/apps/openregister/#/flows/runs?subjectUuid={id}`, the URL the
  `case-flow-runs` rows already open

## D-2. Raw data is a dossiq dialog, not CnObjectMetadataModal

The draft named `CnObjectMetadataModal`. Two things rule it out, either one on
its own. It takes a required `objectData` object, and an `open-modal` action
forwards its props verbatim, so the manifest has no way to hand it the case.
And it renders the `@self` block, which is the metadata around the record
rather than the record: it answers who owns the case and when it was written,
and never shows a stored property.

`CaseRawDataDialog` reads the case from the route, the way `CaseCopyDialog`
does and for the same reason, fetches it from Open Register and prints it.

## D-3. Visibility asks about the reader

The draft named `adminOnly`. There is no such key: an action is a closed object
in the nextcloud-vue schema, so a manifest carrying one would not validate.

Of `visibleWhen`'s three modes only `endpoint` can ask about the reader. The
local mode dot-paths into the case record and the source mode queries Open
Register objects, and neither knows who is looking. Both actions therefore gate
on `GET /apps/dossiq/api/inspect/availability`, which answers `{isAdmin: bool}`.

Evaluation is fail-safe, so a broken predicate hides the entries rather than
showing them to everyone. That is the right direction here, because hiding is
an affordance and not a control: both surfaces read through Open Register,
which refuses a non-admin itself.

The endpoint carries `#[NoAdminRequired]`, and answers `false` rather than 401
for a signed-out reader. Every case page fetches it, admin or not; an
admin-only route would put a 403 in the network log of exactly the readers
whose answer is "no", and fail-safe would hide the entry anyway, so nothing on
screen would look wrong while every handler's page carried a refusal.
