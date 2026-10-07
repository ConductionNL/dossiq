---
kind: code
depends_on: []
---

# Proposal: flow-nodes-to-their-owners

## Why

dossiq contributed its own flow steps for things other apps now own. It sent
mail and notifications, wrote case fields, evaluated decision tables, rendered
documents, raised decisions and called webhooks, each as a dossiq node, and
most of them twice: once in the configured-action catalogue
(`dossiq.action.*`) and once in the transition vocabulary (`dossiq.*`).

Those capabilities have owners now. OpenRegister ships `send-email`,
`send-notification`, `object-write` and `decision-table`; Filinq ships
`generate-document` (filinq#1224); Decidiq ships `request-decision`
(decidiq#1425); Integriq owns outbound calls. Two copies of one capability
drift, and the copy in the leaf app is the one nobody else maintains.

## What changes

- The nodes and their handlers are removed: `dossiq.action.sendEmail`,
  `notifyRole`, `callWebhook`, `createDocument`, `mergeTemplate`;
  `dossiq.sendEmail`, `webhook`, `notify`, `setField`, `evaluateDecision`;
  and `dossiq.requestDecision`.
- dossiq keeps what only a case can do: `setStatus`, `createSubCase`,
  `createTask`, `askPerson`, `ensureCommittee`, `besluitvormingPublish`, and
  the `resumeTerm` task effect.
- The retired-node table maps every removed type to its replacement, with a
  per-type config translation. The upgrade rewrites stored flows; a step that
  cannot be carried over faithfully is left in place and logged.
- A transition or task effect of a retired type (`sendEmail`, `notify`,
  `setField`, `evaluateDecision`) keeps running, as the translated
  replacement node.
- The seeded case flow, the automatic-action migration and the Generate
  document button use the owners' steps.
- dossiq keeps its side of each: a mail an OpenRegister flow sent about a case
  is filed on the case (FlowEmailSentEvent); a document Filinq generated for a
  case is filed in its dossier (DocumentGeneratedEvent); a decision Decidiq's
  flow step concluded about a case becomes its ZGW besluit.

## Impact

- Uses openregister#4120 (the `externalRecipients` allowlist on the
  send-email step and FlowEmailSentEvent), merged into development.
- The shipped case flow now needs Filinq for its decision document.
- Webhook steps have no replacement that takes a raw URL; they were reported,
  not rewritten. Change `webhook-steps-through-integriq` rewrites them onto
  Integriq's source call step.
