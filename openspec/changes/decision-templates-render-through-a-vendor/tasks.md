# Tasks: decision-templates-render-through-a-vendor

## 1. filinq (dependency, decision 153)

- [x] 1.1 Open the filinq change for ConductionNL/filinq#1253: a template engine `vendor:<sourceSlug>` rendering through integriq's source; link its PR here.
  - filinq `openspec/changes/template-engine-renders-through-a-vendor`, ConductionNL/filinq#2175; it also adds `GET /api/templates/engines`, which task 2.3 reads.

## 2. dossiq

- [x] 2.1 Decision template schema: `engine` (default `filinq`, or `vendor:<sourceSlug>`), in the register file that declares the decision template.
- [x] 2.2 `lib/Service/Beschikking/FilinqTemplateEngineAdapter.php` passes the template's `engine` to filinq and maps a vendor failure to a refusal naming the source (no mock fallback); `tests/Unit/Service/Beschikking/FilinqTemplateEngineAdapterTest.php`.
- [ ] 2.3 The decision template editor offers the vendor engines filinq reports as available.
- [x] 2.4 `lib/Settings/connections.json`: cards for the SmartDocuments and Xential sources, probing reachability through integriq.
- [ ] 2.5 Newman: generate a decision on `vendor:smartdocuments` against integriq's mock source in `tests/integration/dossiq.postman_collection.json`.
- [ ] 2.6 Live check that a clean install with filinq binds filinq's adapter; then close dossiq#3131.
- [ ] 2.7 `openspec/parity/capabilities.json` row 12.11: built when 1 and 2 are live.
