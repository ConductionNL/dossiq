# Tasks: webhook-steps-through-integriq

- [x] Integriq: `SourceRequestedEvent`, its listener and `{{ @item }}` (integriq change `source-requested-event`).
- [x] `RetiredWebhookSteps`: split the URL, request the Source, build the POST source-call step with the old body and timeout; refuse what cannot be carried.
- [x] `RetiredTemplateSyntax::forSourceCall()`: `{{case.a.b}}` becomes `{{ a.b }}`.
- [x] `RetiredNodeMap` and `RetiredNodeTranslator`: both webhook types map onto `openconnector.source-call`.
- [x] `RetiredActionRunner`: a declared webhook carries its transition; `triggeredBy` is set from the acting user.
- [x] Test stub for `SourceRequestedEvent`; tests from the callers: the repair step, the side-effect dispatcher with the real runner, the translator; and `RetiredWebhookStepsTest`.
- [x] Docs: `docs/user-guide/admin/02-automatic-actions.md`.
