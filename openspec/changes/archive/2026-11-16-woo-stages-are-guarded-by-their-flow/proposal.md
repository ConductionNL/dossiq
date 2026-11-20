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

## Decided: every exit records a reason (Ruben, 14 October)

- **D1, nothing found.** Three generic case fields (`lib/Settings/register.d/89-search-outcome-and-admissibility.json`): `documentSearchOutcome` (`documents_found` | `nothing_found`), `documentSearchNote` and `inadmissibilityReason`. A case that records `nothing_found` with a note counts as assessed for `documentAssessment` (`lib/Service/Assessment/DocumentSearchOutcome.php`). An outcome without a note is no record.
- **D2, nothing that can be made public.** `OutcomePublisher::publish()` publishes the decision alone when the case records that nothing is disclosable: a recorded "nothing found", or every document under review assessed and every assessment `niet_openbaar`. opencatalogi's publication schema requires only a title, so a publication without attachments is valid; the delivered set is written with no items. Anything else that leaves the disclosable set empty, such as a partly public document whose redaction is not finished, still refuses `no_publishable_documents`.
- **D3, not admissible.** A move "Niet ontvankelijk" from Beoordeling ontvankelijkheid to Afgehandeld, withheld until `inadmissibilityReason` is filled in.
- **Withdrawal.** The handler's "Ingetrokken door de verzoeker" moves now wait for `withdrawalReason`, as the bezwaar flow's do. The resident's own withdrawal is a status write by portaliq and is not affected.

## Existing cases

- **The pin reaches existing instances.** OpenRegister's import overwrites a seeded object when the seed's `@self.version` is higher than the stored one (`ImportHandler`, object branch). On :8080 the Woo case type is stored at version 0.0.9 with no `workflowDefinition`; the seed is 1.4.7, so the upgrade writes the pin. A case with no binding then follows the shipped flow.
- **Cases bound to the basis flow are rebound.** A new repair step, `RebindCasesOffTheBasisFlow`, runs after the backfill and the package install. For every case type whose `workflowDefinition` names a flow that is not a backfilled basis, each open case still bound to that case type's basis flow (recognised by the backfill's description marker) is bound to the named flow. Closed cases, cases on a flow an administrator built and unbound cases are left alone. A second run changes nothing.

## Not changed

- The basis flow itself stays stored and active; nothing is bound to it once the repair has run. Retiring it is an administrator's choice.

## Rollback

Remove the package and the `workflowDefinition` line. Cases started on the shipped flow keep it until rebound.
