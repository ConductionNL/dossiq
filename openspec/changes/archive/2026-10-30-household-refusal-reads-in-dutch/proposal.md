# Proposal: household-refusal-reads-in-dutch

## Why

On DqSociaalDomein the card "Andere zaken van dit huishouden" read "Deze lijst
kon niet worden geladen" for Pieter. Cause: `GET /api/cases/{id}/household-cases`
answers 403 `household-not-allowed` to a reader outside `dossiq-sensitive`
(on :8080 only thomas.kuiper is in it), and the record list showed any failed
read as a load error. nextcloud-vue `fix/r24-record-list-endpoint-refusal`
now shows the server's sentence through the app's catalogue; this change gives
that sentence its Dutch.

The refusal itself is unchanged: who may see a household is an access decision,
not a display one.
