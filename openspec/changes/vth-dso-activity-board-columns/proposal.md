# Proposal: vth-dso-activity-board-columns

## Summary

DqVthInstellingen draws "Activiteiten uit het Omgevingsloket" with four columns, DSO-activiteit / Wordt zaaktype / Variant / Laatst ontvangen, and a row per activity no mapping links yet, its case type cell in red: "Nog niet gekoppeld: 1 aanvraag wacht". Dossiq read integriq's `dso_activity_mapping` rows directly and showed Note and Active; the case type cell would have rendered each case type entry as JSON.

The table now reads integriq's admin overview (`GET /apps/integriq/api/admin/dso-activities`, integriq PR #2731): the rows with `lastReceivedAt`, then the unmapped activities. Two cell formatters name the case types (or how many requests wait) and the variants; `lastReceivedAt` uses the library's `relativeDate`. The note stays as the activity's second line and a switched-off row gets an "Inactief" pill, so nothing the table showed is lost.

## Dependencies

- integriq `feat/r26-dso-activity-overview` (PR #2731): the endpoint and `caseTypes[].variant`. Without it the table shows its empty text.
- nc-vue `fix/r26-admin-settings-formatters` (24t1l): the shell's `formatters` prop and `cnFormatters`. Without it the formatter ids resolve to nothing.
