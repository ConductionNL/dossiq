# Design: contacts-you-can-find

## Context

`contacts-domain` (merged 2026-09-08) added menu entry `Contacts`, index page
`Contacts` over `brpPerson`, detail pages `ContactDetail` (`/contacts/:id`,
`brpPerson`) and `OrganisationDetail` (`/organisations/:id`, `kvkCompany`),
each carrying a read-only card, a cases list, a contact-moments list and an
audit sidebar, plus header actions for New case and Log contact. It recorded
four deviations from its own design. Two bear on this change: the folder
sidebar shipped not at all rather than hidden, so REQ-ID-4's People-folder
scenario is unmet; and its e2e spec had never executed anywhere at the time it
was written.

The register set is unchanged: `brpPerson` and `kvkCompany` in
`lib/Settings/register.d/25-brp-kvk.json`, both implementing
`https://openregister.app/ns#Requester`, both declaring `objectNameField`
(`displayName` and `tradeName`). The instance holds ten rows of each.

`src/menu-layout.json` is the single place deciding WHERE a menu entry lives
(ADR-037): the manifest says what exists, the layout says where. Five entries
are already relocated into `WorkGroup` by that file.

## Goals

- An organisation is reachable without already holding a case that names it.
- A unified-search hit on a person or an organisation opens their page.
- Zero top-level navigation entries spent, and no new custom page.
- The contacts e2e spec runs, so what it asserts means something.

## Non-goals

- Documents on the contact 360 (row 5.4's other half). It needs a
  contact-to-document join, which belongs to OpenRegister's contacts leaf.
- The folder sidebar. Still blocked, and no longer on the critical path.
- The Requester COLUMN link on `Cases`. Still blocked on nextcloud-vue
  choosing a route from a sibling field.
- BRP and KvK subscriptions (Tier B, B22). The index lists whatever the
  register set holds.

## Decisions

### D1: a second index page, not a folder that swaps the schema

`contacts-domain` D1 chose a `folderSidebar` with People and Organisations
folders and wrote down why only one of them could work. Re-measured against
`@conduction/nextcloud-vue` 2.41.0, both halves still hold:
`CnIndexPage.folderSidebarFolders()` returns `this.folderSidebar.folders`
unfiltered, so there is no `hidden` a folder could carry, and `filterField:
"@self.schema"` is not a filter OpenRegister answers, because the wire form
of a `@self` narrowing is a nested object.

The three options are the same three, re-weighed now that a detail page for
each schema already exists:

1. **Wait for the B10 seam.** Costs nothing to write and leaves ten rows
   unreachable for as long as it takes. Rejected: the deviation has already
   outlived one change.
2. **A `party` view schema copying both into one shape.** Rejected again, and
   for the same reason `contacts-domain` rejected it: a third copy of the same
   person and a `$ref` that contradicts `requester-on-the-case` D1.
3. **A second index page under the Contacts entry.** One page object, one menu
   entry, one relocation. Ships.

Option 3 also reads better than option 1 would have. A folder pane that swaps
the page's schema is a presentation choice between two lists; making it the
thing that decides whether a record type is reachable at all was the mistake,
not the seam. If B10 lands, the folder becomes a nicety over two pages that
already work.

The cost is one more page in a repo whose stated risk is too many pages. It is
paid at rung 4, not rung 5: the entry is a CHILD, and the case page's
fourteen-tab lesson was precisely that complexity accumulates a level down
where nothing counts, so the count is asserted rather than argued —
`tests/vitest/contactsYouCanFind.spec.js` builds the effective menu through
the same `buildManifest` the app runs and requires the top level to be exactly
`Dashboard, WorkGroup, Contacts, CaseObjectsMenu`.

### D2: `open: true`, because a collapsed child is not findable

`CnAppNav.isItemOpen()` reads a local toggle, then `hasActiveChild(item)`,
then `item.open`. `hasActiveChild` is false while the PARENT is the active
route, so a user standing on `/contacts` would see no Organisations entry at
all until they found and clicked a chevron. `WorkGroup` behaves that way today
and gets away with it because its children are the app's most-used pages and
users learn them; a brand-new record type gets no such benefit. `open: true`
is one boolean on the parent and is the difference between shipping a list and
shipping a list nobody finds.

### D3: two deep links, and no `searchable` flag

Measured on the running instance, 2026-09-08:

| what | measured |
|---|---|
| `Schema::$searchable` default | `true` (`OpenRegister\Db\Schema:331`) |
| `brpPerson` / `kvkCompany` searchable | `true`, with no flag in the register JSON |
| search for a seeded person | one hit, correct title |
| its `resourceUrl` | `/apps/openregister/api/objects/23/250/<uuid>` |

So contacts were already in unified search and the result was already
correct; what it linked to was a JSON API endpoint.
`ObjectSearchResultFormatter` asks `DeepLinkRegistryService::resolveUrl()`
first and falls back to `openregister.objects.show` when no app has claimed
the pair. `GenericDeepLinkRegistrationListener` reads `deepLinks[]` out of
`src/manifest.json` verbatim, so two entries close it.

Adding `searchable: true` to both schemas was written and then reverted. It
changes no behaviour, and OpenRegister fast-skips a schema whose version did
not move, so making the flag land at all means a version bump, which means a
re-import on every instance to express an intent the platform already assumes.

That leaves `openspec/specs/case-search-via-or-unified-search/spec.md` saying
something untrue: that the flagged schemas are the searchable ones, and that
an unflagged schema does not appear. The delta corrects the requirement rather
than propagating it. The four existing flags stay: they are harmless, and
removing them is a separate question about a spec this change did not come to
rewrite.

### D4: the e2e assertion that had to be re-scoped

`contacts-domain.spec.ts` asserts there is no Organisations folder with
`getByText(/^(Organisations|Organisaties)$/)` over the whole page. This change
puts an Organisations entry in the navigation of that same page, so the
unscoped matcher would have found the fix and reported it as the defect. It
now asserts the absence of `.cn-index-page__folder-pane`, which is the claim
that was actually meant, and asserts the nav link is present beside it.

## Risks

- **The label `Organisations` already exists in the menu.** `TenantsMenu`
  carries it and means the multitenancy tenant. It is in
  `menu-layout.json#removals`, waived to `openregister:organisation`, so it
  renders nowhere and the two never appear together. A unit test pins that no
  top-level entry carries the label, and the layout file records that
  un-removing `TenantsMenu` requires renaming it first.
- **One more page.** Mitigated by the asserted top-level count and by the page
  being a plain `type: index` with no component, so it costs nothing under
  ADR-100 and is deletable by the same reasoning `menu-layout.json` records
  for `Bezwaren` and `Beroepen`.
