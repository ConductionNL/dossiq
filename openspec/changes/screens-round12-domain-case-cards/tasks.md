# Tasks

- [x] 1.1 Six gated side cards on the simple case page
- [x] 1.2 Spec: the gated cards and their case filters
- [x] 1.3 Case type tabs: Hearing, Advice, Decision on the objection (objections); Indication, Case meeting (social domain); Bids (tenders), each gated by tab `visibleWhen`
- [x] 1.4 Bids carry price score, quality score, total and rank (`supplierTender` 1.2.0) and the Bids tab shows them ranked
- [x] 1.5 The objection's grounds card opens the Overview stack, gated by its own `visibleWhen` (nextcloud-vue 2.94.0, #1725)
- [x] 1.6 Subsidy Grant decisions and Advances tabs, reached through the linked application (openregister `_referenced`, PR #4678); Vaststelling waits, it hangs off the execution record two hops away
- [x] 1.7 Mandate and need-to-know cards, now that data backs them: the need-to-know banner is `side-special-data-health`, read off `caseType.specialDataCategories` (`99-case-type-special-data.json`, "Bijzondere persoonsgegevens: gezondheid. Elke inzage wordt gelogd met het doel."); the mandate line is the decisions card's notice in decidiq's `decidesk-decisions` leaf (decidiq #1778), answered by the delegatie- en mandaatregister
- [x] 1.8 The objection's committee advice is a card under the grounds in the Overview stack (DqBezwaar); the hearing and advice side cards go, their tabs keep every record
- [x] 1.9 The objection example case ships with the bezwaar-beroep package (`examples/`), not with the Zuiddrecht set, so the set never depends on an on-request package
- [x] 1.10 Case type packages `jeugdwet` (Melding Jeugdwet, Sociaal domein) and `aanbesteding` (Aanbesteding, Inkoop en leveranciers), installed on request
- [x] 1.11 Example cases of both ship with their packages (`examples/`, see package-examples), not with the Zuiddrecht set
- [x] 1.12 Bids on the example tender: four bidders with their scores ship with the aanbesteding package (1.1.0, `examples/`)
