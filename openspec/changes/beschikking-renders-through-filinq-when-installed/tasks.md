# Tasks: beschikking-renders-through-filinq-when-installed

Tier: MVP. Kind: code. Row: 12.11. Delivered half: integriq
`document-generation-vendor-adapter`. Related: dossiq#3131, filinq#1253.

## 1. The default

- [ ] 1.1 `lib/AppInfo/Registrar/SubstitutableAdapterRegistrar.php`: a static helper `defaultTemplateAdapter(ContainerInterface)` that answers `FilinqTemplateEngineAdapter::class` when filinq is enabled (through `FleetAppId::isEnabledForUser`) and `MockTemplateEngineAdapter::class` otherwise (design D1).
- [ ] 1.2 The template seam passes that default to `ConfiguredAdapter::resolve()` for an empty key. If `ConfiguredAdapter` takes only a mock class, add an optional `defaultClass` argument; leave every other seam's call unchanged.
- [ ] 1.3 `templateFallbackReason()`: keep the not-installed sentence, drop the installed-but-unnamed sentence, add the chose-the-mock sentence (design D3). Dutch and English entries in `l10n/`.

## 2. The card

- [ ] 2.1 `lib/Service/IntegrationStatusService.php`: the `templates` entry reads simulated from the helper in 1.1, not from the raw key (design D2).
- [ ] 2.2 `lib/Settings/connections.json`: `templates.adapter.simulatedValues` keeps only the mock class; reword `simulatedMessage` to "A mock adapter answers here. No template reaches Filinq. Install Filinq, or clear beschikking_template_adapter if it names the mock."

## 3. Tests

- [ ] 3.1 `tests/Unit/AppInfo/AdapterHonestyTest.php`: one test per scenario of the MODIFIED requirement (filinq absent, filinq present and key empty, mock named).
- [ ] 3.2 A PHPUnit over `IntegrationStatusService` asserting Live with filinq enabled and an empty key, and Simulated with filinq disabled.
- [ ] 3.3 `tests/Unit/Settings/ConnectionsDeclarationTest.php` still passes with the narrowed `simulatedValues`.

## 4. Check

- [ ] 4.1 `openspec validate beschikking-renders-through-filinq-when-installed --strict`.
- [ ] 4.2 Live, on an instance with filinq enabled and the key unset: generate a beschikking from a case and confirm a real file in Files and in filinq's generated documents (dossiq#3131's live check, step 3 without setting the key). Close dossiq#3131.
