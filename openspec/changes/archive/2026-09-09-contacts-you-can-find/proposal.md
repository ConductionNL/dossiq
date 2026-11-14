---
kind: config
depends_on: [contacts-domain]
---

# Proposal: contacts-you-can-find

DQ4 of the round 2 phase 3 plan (`comp-round2/work/PHASE3-PLAN.md`), rung 4
on the placement ladder: an index under an existing domain, plus two lines of
manifest. It finishes `contacts-domain`, which shipped a page for an
organisation and no way to reach it, and closes capability row 5.2. Row 9.9
turned out to be half done already and half wrong about why, which is written
down below rather than around.

## Why

Three claims were re-measured against `development` and the running instance
on 2026-09-08 before a line was written. Two survived, one did not.

**5.2 person and organisation contact registry: still partial.** `Contacts`
is an index over `brpPerson`. `OrganisationDetail` exists at
`/organisations/:id` and is referenced by exactly one thing in the whole
tree: `InitiatorSection.vue`, the initiator card of a case. So the ten
`kvkCompany` rows on the instance are listed by no page, and you can only
reach a company through a case that already names it, which is the problem
Contacts was created to solve, one record type later. `contacts-domain`
recorded the reason: the folder sidebar that was to hold an Organisations
folder could not ship.

**The folder sidebar is still blocked, and the fix no longer waits on it.**
Measured against the installed `@conduction/nextcloud-vue` 2.41.0:
`CnIndexPage.folderSidebarFolders()` returns `folderSidebar.folders` verbatim,
so there is no `hidden` and no per-folder `schema`. Both of
`contacts-domain`'s measurements hold. An `Organisations` index sidesteps the
seam rather than waiting for it, and it is the better answer anyway: a folder
that swaps a schema is a presentation choice, not the thing that should
decide whether a record type is reachable at all.

**9.9 search over contacts and persons: the premise was wrong.** The plan
says neither schema carries `searchable: true`, so no contact appears in
unified search. Measured on the instance: `OpenRegister\Db\Schema` declares
`protected bool $searchable = true`, so an unflagged schema is searchable, and
the API reports `searchable=true` for both `brpPerson` and `kvkCompany` with
no flag anywhere in the register JSON. A search for a seeded person already
returned that person. What was wrong was where the result went:

```
title       Stephan Janssen
resourceUrl /apps/openregister/api/objects/23/250/879adc89-...
```

That is the JSON API endpoint. `ObjectSearchResultFormatter` asks the deep
link registry first and falls back to OpenRegister's own object route when no
app has claimed the `(register, schema)` pair, and dossiq's manifest claims
four pairs, none of them a contact. So the gap is two `deepLinks` entries and
nothing else. **No `searchable` flag is added**: it would change no
behaviour, and it carries a schema version bump, which forces a re-import on
every instance for nothing.

**5.4 contact 360: the documents half stays deferred.** Cases, contact
moments and the audit sidebar already ship on both detail pages. Documents
need a contact-to-document join that belongs in OpenRegister's contacts leaf
(B01), and nothing here anticipates it.

**And the e2e spec had never run.** `contacts-domain.spec.ts` executed for the
first time on 2026-09-08 and failed in `beforeAll` on
`nature: 'vraag'`, which is not one of the six values `contactmoment.nature`
allows. One `✘` at the hook, nine tests that never started. The file this
change extends has to work before it can guard anything.

## What changes

- One index page `Organisations` (route `/organisations`, type `index`,
  register `dossiq`, schema `kvkCompany`) listing trade name, KvK number,
  legal form, place and description, whose row action opens the
  `OrganisationDetail` page that already exists.
- One menu entry `OrganisationsMenu`, relocated under `Contacts` by
  `src/menu-layout.json`. **Nav cost: zero.** ADR-097 Decision 1 counts
  top-level entries, and the built menu still carries four.
- `Contacts` gains `open: true`, so the child renders on mount. `CnAppNav`
  auto-expands a group only when a CHILD is the active route, so on
  `/contacts` itself the entry would otherwise be behind a chevron, and a nav
  entry you must first expand to discover is not an answer to "contacts you
  can find".
- Two `deepLinks` entries: `brpPerson` to `/apps/dossiq/contacts/{uuid}` and
  `kvkCompany` to `/apps/dossiq/organisations/{uuid}`.
- `tests/e2e/contacts-domain.spec.ts`: the `nature` fix, a seeded company,
  the Organisations index, and a search-result assertion that reads the OCS
  provider rather than the page.
- No PHP. No new component. No schema change. No custom page.

## Capabilities

### New capabilities

None.

### Modified capabilities

- `initiator-display`: the organisations index replaces the folder sidebar as
  the way to reach a company, and REQ-ID-4 says what ships rather than what
  was hoped for.
- `case-search-via-or-unified-search`: a person and an organisation carry a
  deep link, so a search result opens their page instead of a JSON endpoint,
  and the requirement stops claiming that the `searchable` flag is what puts
  a schema in search.

## Impact

- `src/manifest.json`: menu entry `OrganisationsMenu`, `open` on `Contacts`,
  page `Organisations`, two `deepLinks`, and the `Contacts` page note.
- `src/menu-layout.json`: one relocation.
- `tests/vitest/contactsYouCanFind.spec.js` (new),
  `tests/e2e/contacts-domain.spec.ts`.
- Nothing in `lib/`.
