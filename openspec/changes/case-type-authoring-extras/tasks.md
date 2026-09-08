# Tasks: case-type-authoring-extras

Tier: MVP. Kind: config with two code pieces (D2, D5). Every checkbox is one
implementation task; the criteria under a task are plain bullets.

## 1. Status colour and visibility

- [x] 1.1 `lib/Settings/dossiq_register.json`, schema `statusType`: add
  `colour` (enum, D1) and `hiddenInLists` (boolean, default false).
  - `@spec openspec/specs/case-types/spec.md`
  - `statusType` 1.1.0 -> 1.2.0. OpenRegister fast-skips a schema whose
    version did not move, so the properties would be inert without the bump.
  - The list filter needed a THIRD property the design does not name.
    `status` is a `$ref`, and a filter key `status.hiddenInLists` dot-paths
    into a referenced object: OpenRegister answers no such filter and drops
    it, which is a default filter that silently lists everything. The case
    already carries `isFinalStatus`, a materialised
    `x-openregister-calculations` entry over `@ref.statusType.isFinal`, so
    `hiddenInLists` follows it exactly: `case.statusHiddenInLists`, `case`
    1.17.0 -> 1.18.0. Same caveat as `isFinalStatus`: after editing a
    statusType flag, run `occ openregister:rematerialise-calculations`.
- [x] 1.2 `src/manifest.json`: page `WorkflowBoard` column header and the
  status badge on `CaseDetail` read `colour`; page `Cases` gains the default
  filter `status.hiddenInLists: false`.
  - Only the Cases half is manifest. `WorkflowBoard` is a `type: custom`
    page and the badge on `CaseDetail` is the `case-transitions` custom
    widget, so neither reads a manifest key: the board merges statuses into
    columns in `WorkflowBoard.vue` (`mergeColumnColour`, because two case
    types can colour one status name differently and the merged column can
    only be one of them), and the strip takes `statusColour` off the
    `/available-transitions` answer it already fetches.
  - `CnStatusBadge` was the obvious component and is the wrong one: it takes
    one of six fixed semantic variants, and a colour here is one of twelve
    hue names. Mapping twelve onto six would render two deliberately
    different statuses identically. `StatusBadgeCell.vue` instead, a cell
    widget, because a `formatter` returns a string and a string carries no
    colour.
  - The filter key is `statusHiddenInLists`, not `status.hiddenInLists`
    (see 1.1), and it goes on the `All` chip alone: `All` is the chip marked
    `default`, and `Unclaimed` must stay literally equal to Queue's base
    filter, which a manifest vitest asserts.
- [x] 1.3 `lib/Settings/register.d/*status*.json`: seed colours per role and
  `hiddenInLists: true` on final statuses per design.
  - There is no `*status*.json`: the seeded `statusType` rows live in
    `lib/Settings/dossiq_register.json` (14 rows, each with a `role`) and
    `lib/Settings/register.d/46-demo-cases-english.json` (17 rows, none with
    a role, coloured by name instead). Both carry the colours now.
  - The e2e fixture's own Afgehandeld status (`tests/e2e/helpers/fixtures.ts`)
    deliberately keeps `hiddenInLists` unset, so `case-list-lenses`'s "All
    shows ... the closed one" stays true: closed is not the same claim as
    hidden, and the spec words the filter as hidden.

## 2. A parent type

- [x] 2.1 `lib/Settings/dossiq_register.json`, schema `caseType`: property
  `parentCaseType` (`$ref` caseType). `caseType` 1.2.0 -> 1.3.0.
- [x] 2.2 `lib/Service/CaseTypeResolver.php` (new) per design D2, with a
  cycle refusal and unit tests in `tests/Unit/Service/CaseTypeResolverTest.php`.
  - It arrived as ONE class and phpmd refused it: overall complexity 73
    against a threshold of 50. Split the way `CaseStatusStore` was split out
    of `StatusTransitionService` — `lib/Service/CaseTypeStore.php` owns every
    OpenRegister read (and writes nothing), the resolver owns the merge, the
    chain and the cycle refusal.
  - The merge key is the row's NAME, lower-cased and trimmed. It cannot be
    the id: ids are minted per install, so a child has no way to name the
    parent row it means to override.
  - Only `null`, `''` and `[]` count as "the child said nothing". An explicit
    `false` is an answer, and inheriting it would turn every child of a type
    that allows suspension into one that allows it too.
  - `@spec openspec/specs/case-types/spec.md`
- [x] 2.3 Move every reader of `statusType`, `resultType` and
  `propertyDefinition` by `caseType` to the resolver; list them first with
  `grep -rn "'caseType'" lib/Service` and record the list here.
  - MOVED. `Transitions/StatusTypeLookup::statusRowsFor()` — the one that
    matters, because `idForName`, `idForRole` and `statusesOf` all run
    through it, and so does every transition. `Transitions/CaseResultWriter::
    listResultTypeIds()` — a child that inherits its results closed with no
    result at all otherwise, which is the exact hole that class exists to
    close. `src/components/case/CaseStepsWidget.vue` — the stepper, now over
    `GET /api/case-types/{id}/blueprint` (new, `CaseTypeController`), which
    is also what gives a page the `origin` marker per row.
  - NOT MOVED, on purpose. `ZgwZtcRulesService` (two readers): the ZGW
    catalogue mirrors what is DECLARED, which is what a ZGW client asked
    for, and its constructor is `ZgwRulesBase`'s, shared by every
    `Zgw*RulesService`. The publish validation it also carries is the one
    place that mattered, and `CaseTypePublishService` answers that through
    the resolver instead. `CaseTypeCopyService`, `CaseDefinitionExportService`,
    `TemplateLibraryService`, `SeedDataService`, `VTHTemplateService`,
    `BesluitvormingTemplateService` and the `Repair/` seeders all WRITE
    declared rows; resolving there would copy a parent's statuses into the
    child as new rows, which is the opposite of inheriting them.
  - ⚠️ NOT MOVABLE. `case.status` carries `x-relation-filter: {caseType:
    "@object.caseType"}` and `case.caseType` an `x-openregister-prefill`
    (`status <- initialStatus`). Both are OpenRegister's, both read the
    child's OWN rows, and neither can express a chain. So the status PICKER
    on a new case of a child type offers only the child's own statuses.
    `effectiveCaseType()` inherits `initialStatus` so every PHP reader is
    right; the picker is filed as an OpenRegister request.
- [x] 2.4 `src/manifest.json` page `CaseTypeDetail`: widget
  `case-type-parent` and the Inherited badge per design D2.
  - `parentCaseType` is a field on the `case-type-core` `data` widget rather
    than a widget of its own: a single reference field does not earn a card.
  - The Inherited badge needed a CUSTOM widget, `case-type-blueprint`. The
    design put the badge on an `object-list` column `origin`, and an
    object-list fetches OpenRegister itself: the only question it can ask is
    `statusType where caseType = @objectId`, the type's OWN rows. A child
    that inherits its lifecycle has none, so a declared list would render an
    empty table about a type with four statuses. The merge exists only in
    `CaseTypeResolver`; `GET /api/case-types/{id}/blueprint` is how a page
    reads it, and it marks each row own / inherited / shared.
  - It is a LAYOUT cell, not a tab child: a `type: "custom"` widget named as
    a tab child resolves by registry TYPE, finds nothing and renders an
    empty panel without logging. A vitest asserts no tab strip on this page
    ever names it.

## 3. Folders and shared attributes

- [x] 3.1 `lib/Settings/dossiq_register.json`: `caseType.category` (string,
  facet); `propertyDefinition.caseType` out of `required`.
  - `@spec openspec/specs/property-definition-management/spec.md`
  - `caseType` 1.3.0 -> 1.4.0, `propertyDefinition` 1.1.0 -> 1.2.0.
  - The facet comes from `facetable: true` and nothing else;
    `x-openregister-facet` is read by nothing.
  - ⚠️ `case.caseType` carries an `x-openregister-extends-form` block that
    pulls the type's attributes onto the case form with
    `filter: {caseType: "$value"}`. That filter cannot also say "or no case
    type", so a SHARED attribute reaches the Properties tab (which reads the
    resolver) but not the case form. Filed with the picker limitation in 2.3.
- [x] 3.2 `src/manifest.json` page `CaseTypes`: `folderSidebar` on
  `category`; page `CaseTypeDetail`: the Properties tab lists own rows and
  shared rows per design D3.
  - `source: "field"`, NOT the design's `source: "facet"`. CnIndexPage
    resolves four sources -- register, field, custom and files -- and an
    unknown one falls through to `custom`, whose folder list is the absent
    `folders` array: a sidebar declared as a facet renders an empty pane and
    says nothing. `field` derives the folders from the rows' own `category`
    values, which is what a free word can offer.
  - The shared attributes are on the `case-type-blueprint` widget's
    Attributes section, badged Shared, rather than in a second object-list
    under a heading. The design's `filter: {"$or": [{"caseType":
    "@objectId"}, {"caseType": null}]}` is not a filter shape OpenRegister's
    object list accepts, and an unrecognised key is DROPPED rather than
    refused, so that list would have shown every attribute of every type.

## 4. The AVG block

- [x] 4.1 `lib/Settings/dossiq_register.json`, schema `caseType`:
  `processesPersonalData`, `personalDataCategories`, `legalBasis`,
  `verwerkingsactiviteit` per design D4. `caseType` 1.4.0 -> 1.5.0.
  - `@spec openspec/specs/avg-verwerkingenlogging/spec.md`
  - `legalBasis` carries OpenRegister's article 6 vocabulary VERBATIM and in
    English. `lib/Settings/verwerkingsactiviteiten.json` records why: OR's
    `VerwerkingsactiviteitMapper::validate()` refuses anything else, and the
    Dutch spellings this fleet used before failed all seven rows on every
    fresh install. The enum is not dossiq's to translate.
  - The seven codes `verwerkingsactiviteit` accepts are the seven
    `SeedVerwerkingsactiviteiten` upserts, named in the property description
    so an author can read them without opening the seed.
- [x] 4.2 `src/manifest.json` page `CaseTypeDetail`: widget
  `case-type-privacy`. Icon `ShieldAccountOutline` (the design's
  `ShieldAccount` is a real icon but the fleet spells the outline variants;
  either way it is registered in `src/icons.js`, without which gate-60 fails
  and the widget renders NO icon rather than a fallback glyph).
  - `hideEmpty: false`, deliberately: an unanswered legal basis is exactly
    what a data protection officer is looking for, so the empty row IS the
    finding.
- [x] 4.3 [blocked: openregister the verwerkingsregister as a referenceable
  schema] `verwerkingsactiviteit` becomes a `$ref`; until then a string
  with a datalist of seeded codes.
  - STILL BLOCKED, and the interim shipped: a plain string whose description
    names the seven seeded codes. No datalist — a `data` widget builds its
    field from the schema property, and the manifest vocabulary has no
    `datalist` field widget over a list the schema does not enumerate. An
    `enum` of the seven WOULD render a dropdown, and is deliberately not
    used: the register is OpenRegister's and an installation may hold codes
    dossiq never seeded, so an enum here would refuse a legitimate value.
  - The spec's second scenario ("a code outside the register is refused")
    stays `@e2e exclude` for the same reason: nothing can refuse the code
    until the register is referenceable.

## 5. Export, import, duplicate, publish

- [x] 5.1 `lib/Controller/CaseDefinitionController.php`: method `publish`
  per design D5, routed in `appinfo/routes.php` with `#[NoAdminRequired]`
  and an admin guard in the body; unit test in
  `tests/Unit/Controller/CaseDefinitionControllerTest.php`.
  - It went on a NEW `CaseTypeController` instead. Adding it to
    `CaseDefinitionController` took that class to fourteen collaborators and
    a ten-parameter constructor, which phpmd refuses (CouplingBetweenObjects,
    ExcessiveParameterList). The split is also the honest one: that
    controller owns the portable ZIP package, and these are keyed on a case
    type. Tests are `tests/Unit/Controller/CaseTypeControllerTest.php` and
    `tests/Unit/Service/CaseTypePublishServiceTest.php`.
  - `validate` could NOT be reused: `CaseDefinitionController::validate()`
    validates an uploaded PACKAGE, not a case type, and
    `ZgwZtcRulesService::validatePublish()` reads the type's OWN statuses, so
    a child that inherits its lifecycle would be refused with "give it a
    status" while its page showed four. `CaseTypePublishService` validates
    through the resolver instead.
  - Three routes, not one: `GET /publish/validate` answers the findings
    without publishing, because a person about to be refused should be told
    before being made to write the change note.
  - `@spec openspec/specs/zaaktype-versioning/spec.md`
- [x] 5.2 `src/manifest.json` page `CaseTypeDetail`: the four header
  actions and the widget `case-type-versions` per design D5.
  - RECORDED, because the question the task asks has a different answer than
    it expects: **there is no `run-action` header-action type at all.** The
    manifest schema enumerates handler, open-modal, open-page, navigate,
    object-op, export, open-form, refresh, api-call, agent and toggle, and
    `CnActionButtons` resolves exactly those. All four actions as designed
    would have been refused by `npm run check:manifest`, and had they passed
    they would have rendered four buttons that dispatch nothing.
  - Export is an `api-call` with `download: true`, which asks for the
    response as a blob and hands it to the browser -- the endpoint already
    answers a `DataDownloadResponse`, so no `handler` fallback is needed.
  - The other three are dialogs, each for a reason a declarative action
    cannot meet: Import takes a FILE (a confirm gate has no fields and an
    api-call sends JSON), Publish must show the findings BEFORE asking for a
    change note, and Duplicate must LAND on the copy rather than refresh the
    page you are already on.
  - The dialogs read the case type off the ROUTE, not off their props:
    `open-modal` forwards props verbatim, so a `@objectId` token would
    arrive as that literal string. A vitest asserts no modal action carries
    one.
  - Publish is gated on `isDraft`, so a published type does not offer it.
- [x] 5.3 `l10n/en.json` and `l10n/nl.json`: Colour, Hidden in lists,
  Parent case type, Inherited, Category, Shared attributes, Personal data,
  Export, Import, Duplicate, Publish, Change note, Versions.
  - 🔴 TWO HELPERS HAD TO CHANGE SHAPE FIRST. `caseTypeBlueprint.js` and
    `caseTypePublish.js` held their strings as literals and took a translate
    CALLBACK. `tests/l10n/check-l10n.js` extracts a translatable string by
    finding a literal inside a `t()` call for this app, so none of those ten
    labels and four refusal sentences was extractable: every one would have
    reached l10n/en.json never, a translator never, and a Dutch reader in
    English, with the l10n check green throughout. Both now take
    already-translated text and the callers build it in literal `t()` calls.
  - A docblock that SPELLS a t() call is extracted as a key. "..." landed in
    en.json from five comments explaining this very rule; the comments are
    reworded and the key removed.

## 6. Verification

- [x] 6.1 Add `tests/e2e/case-type-authoring-extras.spec.ts` covering every
  scenario of the deltas that names it. 13 tests: every `@e2e`-tagged
  scenario across the five deltas, plus the status badge on the case page,
  which is the other half of REQ-CT-01 and the surface a handler actually
  reads a colour on.
  - The colour is asserted as the `data-colour` NAME, not as a painted pixel.
    The badge resolves `var(--nl-color-orange, #e17000)` and which of the two
    a browser paints depends on whether the instance carries the NL Design
    System theme: asserting the computed colour would make the spec pass or
    fail on theming rather than on this change. The name says which colour
    the code CHOSE, and choosing grey for everything is what was broken.
  - `tests/e2e/ci-seed.sh` gains `resultType` and `propertyDefinition` in its
    required-schema list. That list is what proves the register imported; a
    schema the suite writes to but the list omits fails at the first POST
    with a message about the object, not about the import.
  - Two scenarios stay `@e2e exclude` as the deltas wrote them: the import
    needs an OS file dialog, and a verwerkingsactiviteit code cannot be
    refused until OpenRegister exposes the register (task 4.3).
- [x] 6.2 Run `composer check:strict`, `npm run check:manifest`, the hydra
  gates and the unit suite locally; read the exit codes, not the summaries.
  - `composer check:strict` was run as its SEPARATE legs (it exceeds a 300s
    budget as one command), and phpmd per directory (printing nothing is its
    OOM signature, so a whole-`lib/` pass that says nothing proves nothing).
  - FOUR REAL DEFECTS THE GATES FOUND, none of which any other check saw:
    - gate-14 route-reachability: `appinfo/routes.php` still carried
      `caseDefinition#blueprint` and `caseDefinition#publish` from the first
      attempt, after the controller they pointed at was reverted. A route
      whose target method does not exist is a 500 at dispatch time.
    - gate-9 semantic-auth: `#[NoAdminRequired]` over a body that admits only
      admins, which is what this task's own text asked for. The gate is
      right and the task was wrong: the pair reads to a reviewer as an
      endpoint anyone may call. Both methods now carry
      `#[AuthorizedAdminSetting(AdminSettings::class)]` and the body guard is
      gone, so the check happens before the method runs.
    - gate-6 orphan-auth: `assertNoCycle()` was defined and never called --
      950 lines of the same shape as the orphaned-read-capability finding.
      Wired into `CaseTypePublishService::validate()`, which is the only
      write dossiq owns: a case type is saved straight to OpenRegister's
      object API by the page, so "refused on save" can be met nowhere else.
    - gate-16 spec-coverage: 34 changed frontend methods with no `@spec`.
  - `ManifestColumnBindingTest` caught a fifth: the Versions list bound its
    Updated column to `updated`, which `workflowTemplate` does not declare --
    a column that renders a dash in every row and says nothing. It is
    `@self.updated`, OpenRegister's metadata envelope, as `WorkflowDefinitions`
    already spells it.
