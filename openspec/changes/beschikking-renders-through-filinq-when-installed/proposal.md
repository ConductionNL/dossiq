---
kind: code
depends_on: []
---

# Proposal: beschikking-renders-through-filinq-when-installed

The dossiq half of matrix row 12.11 (external document generation service,
SmartDocuments and Xential). The delivered half is integriq's
`document-generation-vendor-adapter` (archived 2026-09-28, integriq#2080). It
renders through SmartDocuments or Xential when filinq asks it to. The filinq
half, a template engine of `vendor:<sourceSlug>`, is open as
ConductionNL/filinq#1253 and is not written here.

## Why

A besluit on a dossiq case never reaches filinq on a fresh instance, so it
never reaches SmartDocuments or Xential either. The template seam binds
`MockTemplateEngineAdapter` whenever `beschikking_template_adapter` is empty,
even with filinq installed and enabled (dossiq#3131). The mock answers a made-up
file id, a checksum of that id and a page count of 4. No file is written.

So the vendor chain is three links long and the first link is off by default:

| link | owner | state |
| --- | --- | --- |
| dossiq hands the besluit to filinq | dossiq | mock unless an admin sets one key by hand |
| filinq hands a vendor template to integriq | filinq | open, filinq#1253 |
| integriq renders through SmartDocuments or Xential | integriq | built, archived |

This change fixes the first link. It is the only one dossiq owns.

## What changes

- With `beschikking_template_adapter` empty and filinq enabled, the template
  seam binds `FilinqTemplateEngineAdapter`, not the mock.
- The mock still answers on an instance without filinq, and when an admin names
  it on purpose.
- The Document templates card on the Integrations page reads what the seam
  actually bound. An empty key with filinq enabled reads Live, not Simulated.
- The warning in the log names only the case that is still true: filinq is not
  installed, or an admin chose the mock.

## Not in this change

- The vendor template engine in filinq (filinq#1253).
- Choosing a vendor per case type. A case type's besluit template is a filinq
  template; filinq decides whether that template renders through twig or a
  vendor. dossiq passes the template id as it does today.
- Waiting for a vendor render that finishes later. When filinq#1253 lands with
  an asynchronous answer, dossiq follows filinq's contract in a change of its
  own.

## Rows

- 12.11 External document generation service (SmartDocuments, Xential).
  `built.change` moves to this change; the note keeps
  `integriq/document-generation-vendor-adapter` named.

## Impact

`lib/AppInfo/Registrar/SubstitutableAdapterRegistrar.php`,
`lib/Service/IntegrationStatusService.php`, `lib/Settings/connections.json`,
`tests/Unit/AppInfo/AdapterHonestyTest.php`. No schema change, no new route.
