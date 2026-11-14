# Decision templates render through a vendor

## Why

Row 12.11 (external document generation service, SmartDocuments or Xential). The dossiq seam is done: `lib/Service/Beschikking/TemplateAdapterChoice.php` binds `FilinqTemplateEngineAdapter` when filinq is enabled and `beschikking_template_adapter` is empty, and integriq ships the SmartDocuments and Xential bindings (archived `document-generation-vendor-adapter`, integriq#2080). The middle link is missing: filinq has no template engine that renders through a vendor (ConductionNL/filinq#1253, open). So no decision reaches SmartDocuments or Xential from a case. dossiq#3131 (mock bound by default) is answered by `TemplateAdapterChoice` and can be closed once this change is checked live.

## What changes

- In filinq (decision 153: build the dependency there): a template can name the engine `vendor:<sourceSlug>`, which renders through integriq's source of that slug.
- In dossiq: a decision template carries the engine it renders with; the template editor offers the vendor engines filinq reports; the integrations page shows whether each vendor source is reachable.

## Impact

- filinq: the vendor engine (its own change and PR, tracked by filinq#1253).
- dossiq: the `engine` field on the decision template schema, the template editor, `FilinqTemplateEngineAdapter` passing the engine through, `lib/Settings/connections.json` cards for the two vendor sources.
