# Proposal: task-holds-up-next-status

## Why

Board DqTaak's "Wat deze taak tegenhoudt" reads "De zaak gaat pas naar Beslissing op bezwaar als deze taak klaar is.
De flow wacht hierop sinds 5 okt." Live showed a generic notice: "A case is waiting on this task. Complete the task
and the case moves on."

## What changes

- `GET /api/cases/{caseId}/next-status` (CaseNextStatusController, CaseNextStatus): the status after the case's
  current one in its case type's order, in the user's language. It answers `{}` at the last status and 404 for a case
  the user may not read. This is the server twin of `src/utils/caseNextStep.js`; no flow internals are read.
- The task page's side card (`side-waiting`) becomes a facts card on that endpoint:
  - "De zaak gaat pas naar {name} als deze taak klaar is.";
  - "De flow wacht hierop sinds {created}." (the task's own date, longdate).
- The case link stays on the Zaak card above it.

## Scope

dossiq only. The date format needs nextcloud-vue feat/facts-longdate-format (24t1m). 2 l10n keys.
