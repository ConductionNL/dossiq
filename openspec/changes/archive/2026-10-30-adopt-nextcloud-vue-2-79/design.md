# Design: adopt-nextcloud-vue-2-79

## D-1 The funnels follow the board

DqZaken (https://identity.conduction.nl/screens/board?id=dossiq/DqZaken)
draws a funnel on each of the five columns of the simple Cases list. The
library shows header filters by default (`CnIndexPage` prop `headerFilters`,
default `true`) and a column opts out with `filterable: false`. The simple
overlay therefore sets neither: removing `headerFilters: false` is the whole
change. No spec required the filters off; the flag came from a board compare
that read the board differently.

## D-2 The flag lives on the definition, under the library's own name

nextcloud-vue 2.79.0 reads `hideOnCreate` from a definition record through
the extends-form map, with `hideOnCreate` as the default field name. dossiq's
map on `case.caseType` names it anyway (`hideOnCreate: hideOnCreate`), so the
case form does not depend on a library default, and `RENDERER_ROLES` in
`src/services/propertyVocabulary.js` records why a role OpenRegister's
vocabulary does not name is forwarded. That entry is also what
`DeclaredDisplayFlagHasReaderTest` finds as the flag's dossiq-side reader;
the test now names three declared display flags. The definition schema
declares the field so OpenRegister stores it.

## D-3 Version and digest

Adding a property is content the importer compares, but the schema version
still moves (1.5.1 to 1.6.0) so the change is visible, and
`tests/schemas/schema-version-digests.json` is refreshed with
`php tools/schema-version-digests.php`.
