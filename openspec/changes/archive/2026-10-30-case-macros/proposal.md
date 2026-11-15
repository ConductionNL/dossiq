# Proposal: case-macros

## Why

DqZaakAssistent draws a "Macro's" card with two macros: "Woo-verzoek afronden" (status to Besluit, the Publiceren task)
and "Opschorten voor aanvulling" (suspend the term, the message Aanvullende gegevens). The card (nextcloud-vue
`macros`) lists the macros the case schema declares, and it declared none.

## What changes

- The case schema declares two actions with `macro: true`, `finish-woo-request` and `suspend-for-supplement`, each
  bound by name (openregister macro-flow-by-name) to a manual flow the schema declares in `x-openregister-flows`.
- "Finish the Woo request": `dossiq.setStatus` to Besluit, `dossiq.createTask` Publiceren.
- "Suspend for supplementary information": a new generic node `dossiq.suspendTerm` (SuspendTermHandler, the counterpart
  of ResumeTermHandler, through DeadlinePauseService so the case type's suspension rules apply) for 14 days, then
  `dossiq.askPerson` asking the handler to send the message: the applicant has no Nextcloud identity, so a flow cannot
  message them directly (as in Case behandeling's ask-aanvulling).
- Each changing step carries a `label` ("termijn opgeschort"), which the macro's audit entry and the card's last-run line
  name (openregister macro-run-changes).

## Gaps

- The board's "open het tabblad Documenten" after the first macro: a run's `next` hint is `stay`, `next` or `list`; no
  hint names a tab.
- Both macros sit on the case schema, so they are offered on every case type. A per-case-type choice of macros (the way
  `sideColumnFrom` picks side cards) does not exist yet.
- Declared flows import inert until an administrator adopts them; until then a run is refused, in words, on the card.
- The message to the applicant stays the handler's task until a flow node can write to the portal's message box.
