# Tasks: case-identity

Tier: MVP. Kind: config. Every checkbox below is one implementation task; the
criteria under a task are plain bullets.

## 1. The case number

- [x] 1.1 `lib/Settings/dossiq_register.json`, schema `case`: add the
  `x-openregister-calculations` entry for `identifier` per design D1 and set
  `readOnly: true`; do not add a `format`.
  - `@spec openspec/specs/case-management/spec.md`
  - verify on a live instance that a case posted without `identifier` gets
    `YYYY-0001` and the next one `YYYY-0002`
  - The declaration was already on `development` when this change was applied:
    `readOnly: true`, no `format`, and the exact D1 expression. Nothing about
    it was tested, so it could have been undone by any later edit to a
    10,500-line file without a word. `tests/Unit/Settings/CaseIdentitySchemaTest.php`
    now reads the shipped register and asserts the expression, so the
    declaration this whole change rests on cannot quietly disappear.
- [x] 1.2 `src/manifest.json`: `identifier` out of `new-case`'s form on page
  `Dashboard` and read-only in `case-core` on page `CaseDetail`.
  - `new-case` already omitted it. `case-core` listed it and rendered it
    nowhere: `fieldsFromSchema` drops a `readOnly` property before any
    override is read, so the number was invisible on the case page rather
    than read-only on it. The override is `readOnly: false` (re-admit the
    field) plus `editable: false` (keep it un-typeable); either half alone is
    wrong in a different direction.
- [x] 1.3 ~~[blocked: openregister a `sequence()` function in
  `x-openregister-calculations` scoped per year]~~ UNBLOCKED, and then
  belt-and-braces.
  - OpenRegister ships the operator: `sequence` is in
    `CalculationEvaluator`'s dispatch table and `SequenceService` reserves per
    scope, so the D1 declaration evaluates and 1.2 no longer waits.
  - It ships in the openregister on THIS rig. An install running an older one
    evaluates the same declaration to nothing, in the register, at evaluation
    time — no gate in dossiq can see that, and the symptom is exactly the one
    this change exists to end: a case filed without a number.
  - So `CaseNumberService` + `CaseNumberListener` backfill an EMPTY
    `identifier` on create, in the same `YYYY-NNNN` shape off the same start
    year. When the calculation ran, the field is filled and the service
    writes nothing. The number is MAX + 1 of the year's identifiers, never
    COUNT + 1: a count is one deletion away from handing a live case a number
    another case already holds.

## 2. Tags

- [x] 2.1 `lib/Settings/dossiq_register.json`, schema `case`: property `tags`
  (array of strings, facet) per design D2.
  - The facet key is `facetable: true`, not the design's
    `x-openregister-facet: true`. `filtersFromSchema` reads `facetable` and
    nothing else, and every other faceted property on this schema already
    spells it that way; the design's spelling would have declared a facet no
    sidebar reads.
  - `case` goes 1.14.0 → 1.15.0. OpenRegister fast-skips a schema whose
    version has not moved, so on an install that already holds `case` the new
    property would never land.
- [x] 2.2 `src/manifest.json` page `CaseDetail`: sidebar tab `tags` with a
  `data` widget over `tags` using the tags form widget; page `Cases`: a Tags
  filter in the sidebar.
  - The Cases filter needed no manifest entry at all: `filtersFromSchema`
    builds the index sidebar from the schema's `facetable` properties, so
    2.1 supplied it. The guard for it therefore sits on the schema, not on
    the page.
  - `TagMultiple` is now in `src/icons.js`. An icon named in a manifest and
    missing from the registry renders NOTHING rather than a fallback glyph,
    and hydra gate-60 fails on it.
- [x] 2.3 `l10n/en.json` and `l10n/nl.json`: Tags, Terms and archive,
  Statutory lead time, Legal basis.
  - Sentence case throughout, per the Conduction voice. The archive and
    payment titles already on the schema are Title Case and become visible
    for the first time under 3.2, so they are corrected there rather than
    shipped as new user-facing copy that breaks the voice on arrival.

## 3. Terms and archive

- [x] 3.1 `lib/Settings/dossiq_register.json`, schema `case`: property
  `legalBasis` (string).
  - And two things the design did not foresee, both of which would have left
    the block empty while every gate passed.
  - The five archive and payment properties carried `visible: false`, which
    drops a property from every schema-driven surface at once. They are
    declared, they were never readable, and a widget over them would have
    rendered an empty grid.
  - Their titles were Title Case (`Archive Nomination`). They become
    user-facing here for the first time, so they are corrected to sentence
    case rather than shipped against the voice.
  - `statutoryTerm` is a new calculated property carrying the type's
    processing deadline; see 3.2 for why the design's dotted include path
    could not work.
  - `case` goes 1.15.0 → 1.16.0.
- [x] 3.2 `src/manifest.json` page `CaseDetail`: widget `case-terms` per
  design D3, with `extend: ["caseType"]` and the include path
  `caseType.processingDeadline`; add its layout cell without a reserved void.
  - verify the dotted include renders; if `data` cannot read an extended
    path, show `processingDeadline` on the existing `case-kpi-casetype` stat
    caption and record it here
  - RECORDED: the dotted include cannot render, and neither can the fallback.
    `fieldsFromSchema` filters `Object.entries(schema.properties)`, so an
    include entry that is not itself a property key matches nothing and the
    row is simply absent. The stat caption is no better: `resolvedCaption`
    interpolates its tokens from the ENDPOINT payload only, which is null in
    `objectField` mode, so every token would resolve to the empty string.
  - So the lead time is a real property. `statutoryTerm` copies
    `@ref.caseType.processingDeadline` onto the case through the same
    materialised mechanism `deadline` already uses, and the widget includes a
    property that exists. It renders the type's ISO 8601 duration (`P8W`);
    turning that into the words "8 weeks" needs a duration formatter that
    neither the calculation engine nor the data widget has, and inventing one
    here would be a second numbering-style feature hiding inside a config
    change.
  - The cell is gridX 8, gridY 13, 4 wide and 5 tall: the right column below
    `case-flow-runs`, ending level with `case-panels` at y 18. The design's
    "right of case-core" cell is `initiator`'s and was not free.

## 4. Seed and verification

- [x] 4.1 `lib/Settings/register.d/46-demo-cases-english.json`: identifiers
  in `YYYY-NNNN`, tags and a legal basis on the demo cases per design.
  - The demo cases were never missing their numbers. All ten carried one, in
    the `ZAAK-YYYY-NNNN` shape; the dashboard showed "-" because `identifier`
    is `readOnly` and the data widget dropped it (see 1.2). All ten are now
    `YYYY-NNNN`.
  - Two cases carry `wijk-noord` and six carry no tags, so the filter
    scenario asserts something. One carries `archiveNomination` with NO
    `archiveActionDate`, which is what the empty-row scenario needs.
  - `statutoryTerm` is seeded to match each case's own case type, so a page
    read before the first re-save says the same thing as one read after it.
  - `openspec/specs/my-work/spec.md` quoted `ZAAK-2026-0118` twice as an
    example identifier and now quotes `2026-0118`.
  - HAZARD, recorded rather than fixed: OpenRegister's `sequence` is a
    counter, not a max-of-existing. On a fresh install it starts at 0001 while
    these seeded rows already occupy numbers in the hundreds, so a generated
    number can eventually collide with a seeded one. The dossiq backfill of
    1.3 cannot collide (it is max + 1); the register's own sequence can.
- [x] 4.2 Add `tests/e2e/case-identity.spec.ts` covering every scenario of
  the delta spec that names it (a number on a new case from the form and
  from the API, a kept number on an existing case, tags on the sidebar and
  as a filter, the Terms and archive block).
  - Seven tests, one per scenario. No literal number is asserted anywhere:
    the register's `sequence` is a counter nobody in a test chooses, so the
    form scenario asserts the new number is higher than every number of that
    year the list already held, and the API scenario asserts the shape and
    the year.
  - `statutoryTerm` is asserted over the API before it is asserted on screen.
    A blank cell could be the widget or an empty register, and only the API
    read tells them apart.
  - The filter scenario narrows on a run-scoped tag and seeds three untagged
    cases of the same type, so a filter that does nothing cannot pass it.
- [x] 4.3 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates and the unit suite locally; read the exit codes, not the summaries.
  - Exit codes, every one read from `$?`: `npm run lint` 0, `npx vitest run` 0
    (56 files, 550 tests), `npm run check:manifest` 0,
    `node tests/l10n/check-l10n.js` 0, `npm run check:schema-l10n` 0,
    `npm run format` 0, `composer lint` 0, `phpcs` 0, `psalm` 0, `phpstan` 0,
    `phpunit` 0 (3126 tests, 18218 assertions, 56 skipped).
  - `phpmd` was swept PER DIRECTORY under `lib/`, both rule sets, rather than
    over all of `lib` at once: the whole-tree run is OOM-killed on this box
    and an OOM kill reads as a pass. Every directory exited 0.
  - Hydra gates with `HYDRA_GATE_BASE_REF=origin/development`: exit 0,
    COVERAGE 82 of 90 declared gates reported a result, 82 of 82 applicable
    ran. The one advisory gate (gate-53, 7 WARN) is entirely pre-existing:
    the humaniq cross-app register and six menu-layout removals.
  - Playwright was NOT run.
