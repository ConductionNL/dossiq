# webhook-steps-through-integriq Specification

## Purpose
Carry dossiq's retired webhook steps over to Integriq's source call, so stored flow steps, transition webhooks and migrated callWebhook actions keep working after outbound calls moved to Integriq. The URL's base becomes an Integriq Source and its path the endpoint. A step that cannot be carried over safely is left in place and logged with the reason.

## Requirements

### Requirement: REQ-WSI-001 A retired webhook step becomes Integriq's source call

A `dossiq.webhook` or `dossiq.action.callWebhook` step SHALL be translated into one
`openconnector.source-call` step: `source` is the uuid of the Source Integriq gives for the
URL's scheme, host and port when asked by `SourceRequestedEvent`; `endpoint` is the URL's path
(or `/`) and query; `method` is `POST`; `output` is the step's own `output` or `actionResult`.
The body SHALL be `{"case": "{{ @item }}"}`, or, for a `callWebhook` with a `payloadTemplate`,
that template with `{{case.x}}` rewritten to `{{ x }}` and a `Content-Type: application/json`
header. A `dossiq.webhook` step's own headers SHALL be kept. The Source request SHALL carry the
step's timeout: 5 seconds for `dossiq.webhook`, `timeoutSec` or 10 for `callWebhook`.

#### Scenario: A stored webhook step is rewritten on upgrade
@e2e exclude Repair-step rewrite runs under occ upgrade; RewriteRetiredFlowNodesTest::testAWebhookStepIsRewrittenToASourceCall covers it.

- **GIVEN** a flow with a `dossiq.webhook` step calling `https://hooks.example.org/x`
- **AND** Integriq answers with Source `src-hooks` for `https://hooks.example.org`
- **WHEN** the upgrade runs
- **THEN** the step SHALL be `openconnector.source-call` with source `src-hooks`, endpoint `/x`, method `POST` and body `{"case": "{{ @item }}"}`

#### Scenario: A callWebhook with a payload template keeps its payload
@e2e exclude Translation only; RetiredWebhookStepsTest::testAPayloadTemplateBecomesAStringBody covers it.

- **GIVEN** a `callWebhook` action whose `payloadTemplate` is `{"zaak": "{{case.identifier}}"}`
- **WHEN** it is translated
- **THEN** the body SHALL be `{"zaak": "{{ identifier }}"}` with a JSON content type

### Requirement: REQ-WSI-002 A declared transition webhook posts the case and the transition

A case type's declared `webhook` action SHALL run through `RetiredActionRunner` as the translated
source call, with the transition context as `transition` beside the case in the body, and the
transition's acting user as `triggeredBy` and `runAs`. A failure SHALL be a failed result row and
SHALL NOT roll back the transition.

#### Scenario: A transition webhook runs through Integriq
@e2e exclude The transition side-effect path is backend; SideEffectDispatcherTest::testARetiredWebhookRunsAsASourceCall covers it with the real runner and translator.

- **GIVEN** a transition declaring `{type: webhook, url: "https://hooks.example.org/case-events?kind=status"}`
- **WHEN** user `behandelaar1` moves a case through it
- **THEN** `openconnector.source-call` SHALL run with endpoint `/case-events?kind=status`, body `{"case": "{{ @item }}", "transition": <the transition context>}`, as `behandelaar1`

### Requirement: REQ-WSI-003 A webhook that cannot be carried over is refused with the reason

The translation SHALL refuse with `UnmappableStep`, naming the reason, when the step has no URL,
when the URL is not http or https with a host, when it carries a user name or password, when a
`callWebhook` URL is chosen per tenant at run time, when a header is a credential header
(`Authorization`, `Proxy-Authorization`, `Cookie`, `Set-Cookie`, `X-Api-Key`, `Api-Key`), when
Integriq is not installed, and when Integriq refuses or does not answer the Source request. The
upgrade SHALL then leave the step in place and log the reason.

#### Scenario: Integriq refuses a private address
@e2e exclude Translation only; RetiredWebhookStepsTest::testIntegriqsRefusalIsTheReason covers it.

- **GIVEN** a webhook step calling `http://10.0.0.5/hook`
- **AND** Integriq refuses the host because the instance does not allow local remote servers
- **WHEN** it is translated
- **THEN** it SHALL be refused, and the reason SHALL be Integriq's
