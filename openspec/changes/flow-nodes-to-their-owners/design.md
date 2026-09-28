# Design: flow-nodes-to-their-owners

## The mapping

| Retired type | Replacement | Translation |
|---|---|---|
| `dossiq.action.sendEmail` | `openregister.send-email` | `recipientRef` `email:x` becomes `x`, a field name becomes `{{ field }}`; `subjectTemplate`/`bodyTemplate` rewritten; `externalRecipients: object` |
| `dossiq.sendEmail` | `openregister.send-email` | `to` (or `recipient`) literal; `subject`/`body` as written; `externalRecipients: object`; a stored `template` id is refused |
| `dossiq.action.notifyRole` | `openregister.send-notification` | recipients `{{ role }}` and `{{ roleMembers }}`; title and empty message take the wording dossiq's notifier used |
| `dossiq.notify` | `openregister.send-notification` | `userId` (or `recipient`), else `{{ assignee }}`; title names the transition when known |
| `dossiq.setField` | `openregister.set-fields` + `openregister.object-write` | the value onto the item (`__now__` becomes `{"now": []}`), then an update of that one field on the case, matched on `@self.uuid` |
| `dossiq.evaluateDecision` | `openregister.decision-table` + `openregister.object-write` | the stored table read by key and placed inline; mappings kept; the outputs written to the case |
| `dossiq.action.createDocument` | `filinq.generate-document` | merge fields inlined; text rewritten; `storeFile`, `format: pdf`, metadata with the document type |
| `dossiq.action.mergeTemplate` | `filinq.generate-document` | with `targetField`: `storeFile: false`, the field; without: as createDocument |
| `dossiq.requestDecision` | `decidiq.request-decision` | none, the config is identical |
| `dossiq.webhook`, `dossiq.action.callWebhook` | none | refused, naming the host |

## Template syntax

dossiq rendered `{{case.path}}`, walking dotted paths from a single `case`
root and blanking anything else. OpenRegister's messaging steps read only
top-level item keys, so `{{case.x}}` becomes `{{ x }}` and a deeper path is
refused. Filinq renders Twig with the item at the top level and under `item`,
so `{{case.a.b}}` becomes `{{ item.a.b }}`; text containing `{%` or `{#`,
which dossiq printed and Twig would run, is refused. A placeholder without the
`case.` root rendered empty in dossiq and is removed.

## Why a step can become two

Writing a field and evaluating a table changed the STORED case. OpenRegister
separates computing onto the item from writing the object, so both become a
compute step and an object write. The rewriter keeps the original step id on
the first, names the next `<id>--2`, chains them and moves the outgoing edges
to the last.

## Why webhooks are not rewritten

Every Integriq step reaches the outside through a configured Source
(`openconnector.source-call`, endpoint relative to it) or a subscription
(`openconnector.event-emit`, DeliveryRequestedEvent). None takes a URL. Making
a Source per URL during an upgrade would create configuration nobody chose, so
the step stays and the log names the flow, the step and the host.

## Declared actions keep working

Transition `automaticActions` and task effects are data on case types, not
flows. `RetiredActionRunner` answers for a retired declared type: it
translates the declaration with the same translator and runs the steps through
OpenRegister's catalogue, with the transition's user as `runAs`. The label of
the transition fills a mail's missing subject and a notification's title, as
the handlers did.

## Filing what the owners did

- Mail: OpenRegister dispatches FlowEmailSentEvent per sent mail. When the
  item is a dossiq case, `FlowEmailSentListener` calls
  `CaseEmailService::recordSentEmail()`, the half of `sendEmail()` that stores
  the message and writes the timeline line. A failed record is logged: the
  mail is gone, and a retried step would send it again.
- Documents: Filinq dispatches DocumentGeneratedEvent. When the object is a
  dossiq case, a file was stored and the metadata names a document type,
  `DocumentGeneratedListener` files it through `GeneratedDocumentFiler`
  (ZaakdossierService, addressees by the AddressesTheCase rule). A failed
  filing throws, so the flow step fails as the retired step did.
- The Generate document button dispatches DocumentGenerationRequestedEvent
  with requestingApp `dossiq`, files the result itself so it can answer with
  the informatieobject, and marks the request so the listener stands aside.
  Unhandled with no error means Filinq is absent, which is a refusal naming
  Filinq.
- Decisions: Decidiq's step raises with sourceApp `decidiq-flow` and
  externalReference `flow-run:<run>:<node>`. DecisionConcludedListener accepts
  it when the subject register and schema are dossiq's case, takes the subject
  id as the case, and does not wake the run, which Decidiq does itself.

## Known differences

- A mail step mails an address only when the case holds it
  (`externalRecipients: object`); dossiq's own allowlist of domains is not
  consulted.
- A role notification reaches the people the role fields name; the case
  schema's role-to-group assignment dossiq also asked is not.
- A decision-table step carries a copy of the table taken at upgrade; a later
  edit of the dossiq table does not reach it.
- Filinq renders a missing field as empty where dossiq refused the document.
  The Generate document button still refuses before asking.
- In-flight runs pinned to a published version that names a removed type
  cannot resume on it; the repair rewrites the flow's new version only.
