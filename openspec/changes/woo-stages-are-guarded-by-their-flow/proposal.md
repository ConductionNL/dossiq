---
kind: code
---

# Proposal: woo-stages-are-guarded-by-their-flow

## Why

On 13 October a Woo coordinator (thomas.kuiper, group `behandelaars`) moved a Woo request from Ontvangst to Publicatie with only a free-text reason each time. There were no documents, no assessment and no decision ("Stel het besluit op: Nog doen"), and every move passed.

The cause is configuration, not code. The Woo request case type (`lib/Settings/register.d/81-woo-verzoek.json`) shipped no flow. The upgrade step `MigrateWorkflowDefinitions` then wrote a "Woo-verzoek (basis)" flow: one move per status in order, with no guards. The engine enforced exactly that.

`WOODocumentAssessmentService::allDocumentsAssessed()` says it is "used as a stage-advancement guard before Lakken / Anonimiseren". Nothing calls it.

## What changes

Procedures are case types, flows and configuration (decision 182). So the Woo stages get their rules from a flow, and the engine gets one generic guard kind it lacked.

1. **New guard kind `documentAssessment`** (`lib/Service/Transitions/DocumentAssessmentGuard.php`), registered in `GuardRegistry`. `require: gathered` passes when the case has documents under review. `require: assessed` passes when it has documents under review and none waits for an assessment. An empty case is not "all assessed". A register it cannot read refuses the move and says so. It reads the same answer as `/api/cases/{id}/document-assessments/outstanding`, so it serves any case type that reviews documents.
2. **A shipped Woo flow** in a new auto-install package `lib/Settings/packages/woo-verzoek/` (flows only):

   | Move | From | To | Rule |
   |---|---|---|---|
   | Ontvankelijkheid beoordelen | Ontvangst | Beoordeling ontvankelijkheid | none |
   | Documenten zoeken | Beoordeling ontvankelijkheid | Zoeken documenten | none |
   | Documenten beoordelen | Zoeken documenten | Beoordelen documenten | `documentAssessment: gathered` |
   | Lakken en anonimiseren | Beoordelen documenten | Lakken / Anonimiseren | `documentAssessment: assessed` |
   | Besluit voorbereiden | Lakken / Anonimiseren | Besluit | `documentAssessment: assessed` |
   | Naar publicatie | Besluit | Publicatie | `documentAssessment: assessed`, and withheld until `wooPublicationStatus` is present (the decision route sets it to `ready`) |
   | Afhandelen | Publicatie | Afgehandeld | withheld until `wooPublicationStatus` is `published` |
   | Ingetrokken door de verzoeker | Ontvangst, Beoordeling ontvankelijkheid | Afgehandeld | none (the portal withdrawal needs a direct move from both) |

   "Decision made" and "published" use the existing `requiresSettled` vocabulary (`fieldPresent`, `fieldEquals`), which `execute()` re-checks on the server. No new field guard was needed.
3. **The case type names the flow** as its default route (`workflowDefinition`), version 1.4.7. A fresh install never gets the guard-less basis flow, because the backfill skips a case type that is pinned.

## Decisions needed (not guessed)

These paths are not in the flow. A case on them now needs an administrator's free-form move.

- **D1. Nothing found.** A request where the search finds no documents cannot pass "Documenten beoordelen". Options: (a) a move Zoeken documenten to Besluit, guarded by nothing but a required reason; (b) a `documentAssessment` option that accepts a recorded "no documents found"; (c) keep it admin-only. Recommended: (b), so the empty case is a recorded fact and not a bypass.
- **D2. Nothing disclosable.** When every document is withheld, publishing refuses `no_publishable_documents`, so the case cannot reach `published`. The Woo still requires the decision itself to be made public. Options: (a) let the publish step publish the decision alone and set `published`; (b) a move Publicatie to Afgehandeld withheld until `wooPublicationStatus` is `ready` with a result "Niet openbaar gemaakt"; (c) admin-only. Recommended: (a).
- **D3. Not admissible.** There is no move from Beoordeling ontvankelijkheid to Afgehandeld for a request that is not admissible. Recommended: add it, withheld until a reason field is filled.

## Not changed

- Existing cases bound to the backfilled "Woo-verzoek (basis)" flow keep it: a case keeps the flow it was bound to. They need a rebind (Move to another version) or a one-off repair, which is a data decision for the instance owner.
- Whether the register.d import overwrites `workflowDefinition` on an instance where the backfill already pinned the basis flow is not verified here.

## Rollback

Remove the package and the `workflowDefinition` line. Cases started on the shipped flow keep it until rebound.
