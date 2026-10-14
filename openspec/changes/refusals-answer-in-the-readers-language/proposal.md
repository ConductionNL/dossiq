---
kind: code
---

# Proposal: refusals-answer-in-the-readers-language

## Why

On 13 October a Woo coordinator (thomas.kuiper, group `behandelaars`) closed a Woo request on the demo and read a 422 in English: "Pick a result before closing this case." Staff text is Dutch through l10n.

The refusal carries its sentence in English, which is also its catalogue key. `TranslatesRefusals` sent that sentence out untranslated as `message`. Only the lifecycle dialog mapped `code` back to a Dutch string. Every other surface that reads `message` showed English: the stages widget, the workflow board, any API client.

## What changes

1. `TranslatesRefusals` translates the sentence through the reader's `IL10N` before it goes out as `message`. A controller that injects `IL10N` as `$l10n` is asked first. Otherwise the app's translator from the factory is used, which follows the signed-in user's language.
2. A sentence the catalogue does not know goes out unchanged. A sentence that cannot be formatted goes out unchanged too, never a 500.
3. `error` (the rule slug) and `code` (the slug the frontend maps) stay as they are.
4. `StatusTransitionController` takes an optional `IL10N`, so the test hands one in through the real controller.

## Out of scope

The many refusal sentences that have no catalogue entry yet stay English until they get one. The one the coordinator met has one.

## Rollback

Revert the commit. Nothing is stored.
