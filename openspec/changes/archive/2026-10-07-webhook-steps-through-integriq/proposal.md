# Proposal: webhook-steps-through-integriq

## Why

Change `flow-nodes-to-their-owners` (dossiq#3180) removed dossiq's webhook steps,
`dossiq.webhook` and `dossiq.action.callWebhook`, because outbound calls belong to Integriq
(hydra ADR-094). It could not map them: Integriq's `openconnector.source-call` calls a
configured Source with an endpoint relative to it, and a webhook step holds a plain URL. The
upgrade left those steps in place and logged them, so every stored webhook step, every
case-type webhook on a status transition and every migrated `callWebhook` action failed at run
time. That is a regression, and this change closes it.

## What changes

- Integriq gains `OCA\Integriq\Event\SourceRequestedEvent` (integriq change
  `source-requested-event`): find or create the Source for a base URL, idempotent by a slug
  derived from scheme, host and port, refusing anything that is not a bare http(s) base URL on a
  host the instance may call. Integriq's flow template gains `{{ @item }}`, the whole item.
- `RetiredWebhookSteps` translates a webhook step: the URL's base is requested as a Source, its
  path and query become the endpoint, the method is POST, and the body is what the step sent:
  `{case}` for a stored flow step, `{case, transition}` for a declared transition webhook, the
  rendered payload template for a `callWebhook` with one. The timeout of a newly created Source
  is the one the step used (5 seconds for `webhook`, `timeoutSec` or 10 for `callWebhook`).
- `RetiredNodeMap` maps both types onto `openconnector.source-call`, so the upgrade rewrites
  stored flows, the automatic-action migration builds flows from `callWebhook` actions, and
  `RetiredActionRunner` runs declared `webhook` actions through the same translation.
- `RetiredActionRunner` also sets `triggeredBy`, the run owner Integriq's nodes require, from
  the acting user.

## What stays refused

A step is left in place and logged, with the reason, when Integriq is not installed or gives no
Source, when its URL is not http(s) or carries a user name or password, when its URL is chosen
per tenant at run time (`urlSlug` in a tenant-keyed `urlMap`), or when it sends a credential
header, which Integriq takes from the Source.

## Known differences

- A stored flow step posted the engine's run context as `transition`. No step can reach that
  context, and it was never a transition, so a rewritten flow step posts `{case}` only.
- A Source is shared by every step calling the same base URL, so only the first request sets
  its timeout.
- A declared webhook on a transition the system made, with no acting user, fails: Integriq
  calls only on behalf of a user.

## Order

Integriq's PR merges first. Dossiq's request is guarded by `class_exists()` and refuses the step
with a logged reason while Integriq lacks the event, so neither order breaks anything.
