---
kind: code
---

# Proposal: the-redaction-stage-waits-for-every-redacted-copy

## Why

The Woo request's move from "Lakken / Anonimiseren" to "Besluit" required only that every gathered document was assessed. A partly public document without a redacted copy is skipped by the publication (`PublicationSources::collectDelivery()`), so a case could reach its decision with documents that would silently not be published. The redaction stage is done when every partly public document has its redacted copy (`redactedDocumentRef`), which change `partly-public-documents-get-a-redacted-copy` now writes.

## What changes

1. **The `documentAssessment` guard gets a third requirement, `redacted`**: it passes when every document of the case assessed as partly public has a redacted copy. It reads the assessments regardless of the caller, as the outstanding list does, so an assessment the handler cannot read still holds the move. A register it cannot read refuses the move and says so. Generic: any case type that assesses documents can put it on a move.
2. **The Woo request flow** puts `documentAssessment: redacted` beside `documentAssessment: assessed` on "Besluit voorbereiden" (Lakken / Anonimiseren to Besluit).
3. **The `woo-verzoek` package goes to 1.1.0**, so an existing install refreshes the flow row it never edited on the next upgrade (package-upgrade-refreshes-untouched-rows, REQ-CT-49). A flow edited on the instance is kept and logged.
