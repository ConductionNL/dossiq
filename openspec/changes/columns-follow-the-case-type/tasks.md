# Tasks: columns-follow-the-case-type

Tier: V1. Kind: config. Row 11.9.

- [x] 1.1 Raise `@conduction/nextcloud-vue` in `package.json` to the version
  carrying nextcloud-vue PR 1213, and confirm `CnIndexPage` resolves scope
  columns in the built bundle rather than only in the library source.
  - `@spec openspec/changes/columns-follow-the-case-type/specs/case-management/spec.md`
  - NOT RAISED, because there is no such version. Measured 2026-09-18: the
    installed 3.2.0 and the newest published 3.3.0 (released that morning)
    carry no `src/utils/scopeListLayout.js` and no scope resolution in
    `CnIndexPage`. PR 1213 is on nextcloud-vue `parity/round2` and in no
    release. `package.json` stays at `^3.2.0`: raising it to a version that
    does not carry the code buys nothing and collides with every other lane.
    The declarations below are inert until that release lands, and the e2e
    spec is red and says why in its header rather than passing on a page
    nothing changed.
- [x] 2.1 `src/manifest.json` `#Cases`: declare `columns` per `caseType` scope
  on the `folderSidebar`, and leave the All types folder on the page columns.
  - NOT WHERE THIS LIVES, and that is the change's main finding. dossiq's
    sidebar is `source: "register"` over `caseType`, so it has NO manifest
    folder entries to hang `columns` on. For a register-derived folder list
    `CnIndexPage` reads the same three keys off each ROW's `x-index` block,
    keyed by the folder id. So the declaration is on the case type RECORD,
    which is task 3.1, and is also where a municipality can edit it.
  - What the manifest DID need: the three columns a case type asks for
    (`besluitdatum`, `procedureType`, `riskLevel`) are now declared on the
    page. A scope selects from the page's set and cannot add to it, so a
    column the page does not carry is dropped by the library in silence.
- [x] 2.2 `#Cases`: declare `sort` per scope where the type has a date that
  orders it better than `createdAt`.
  - The key is `defaultSort`, not `sort`: that is what `resolveScopeLayout`
    reads, and a scope spelling it `sort` declares no order at all, silently.
- [ ] 2.3 `#Tasks`: the same, scoped to the case type of the task's case.
  - OUT OF REACH HERE, measured rather than skipped. `#Tasks` is an
    `entitySource: "tasks"` index: it fetches from the task engine, not from
    a register, so there is no register and schema for a `source: "register"`
    folder sidebar to build case-type folders from, and the engine's query
    grammar is `scope` / `isTerminal` / `dueAfter`, with no case-type filter.
    The page also declares no `columns` of its own, so there is no set for a
    scope to select from. Giving Tasks a case-type sidebar is a change about
    the task engine's query, not a declaration, and it belongs in its own.
- [x] 3.1 Seeded case types declare their columns: omgevingsvergunning shows
  the decision date and the expiry, bezwaar shows the contested decision and
  the hearing date, klacht shows the channel and the receipt date.
  - The case types are the six VTH ones in `vth_seed_data.json`, which are
    what this app actually seeds. There is no klacht case type and the
    bezwaar ones are under `_caseTypes_disabled`, so three of the six carry a
    layout: the omgevingsvergunning shows `procedureType` and `besluitdatum`,
    the toezichtzaak shows `riskLevel` and `assignee`, the handhavingszaak
    shows `riskLevel` and `besluitdatum` ordered newest decision first. The
    permit's expiry date is NOT declared, because no case property holds one
    and a column naming a property nothing answers to is the defect REQ-CM-72
    exists to catch.
  - `lib/Settings/register.d/39-case-type-list-layout.json` declares `x-index`
    on `caseType`. Without it the magic mapper drops the block on the way in,
    the save answers 200, and the page behaves like a case type that declared
    nothing.
- [x] 4.1 `tests/vitest/`: every scope column names a property its case type
  carries, and a scope without `columns` inherits the page's.
  - `tests/vitest/caseTypeListColumns.spec.js`, 15 assertions. Mutation
    checked: renaming one seeded column to `vervaldatum` reddens the
    assertion that names the case type and the column, which is REQ-CM-72's
    scenario exactly.
- [x] 4.2 `tests/e2e/columns-follow-the-case-type.spec.ts`: pick a type in the
  sidebar, the header row changes; pick All types, it changes back.
  - Reads the case type back from OpenRegister first, because reading the
    seed file would report green on a block the mapper dropped.
