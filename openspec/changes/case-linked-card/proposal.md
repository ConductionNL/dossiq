# case-linked-card

## Why

The case page showed planninq's projects as an undesigned widget of its own ("0 Projecten", a sentence and two buttons).
DqZaak, the canon, draws projects inside the "Gekoppeld" side card with the other apps' links: "Leges en betalingen,
contract en projecten: niets gekoppeld", then "Nieuw project" and "Openen in planninq".

## What changes

- The simple case page's side column gets `side-linked`, nextcloud-vue's `linked` widget (linked-integrations-card),
  right after Handling, listing `filinq-documents`, `shillinq-payment-requests-panel`, `shillinq-contracts-panel` and
  `planninq-projects`.
- The `case-projects` widget and its grid cell (`work-projects`) are removed.

## Dependencies

nextcloud-vue `feat/linked-integrations-card`; each leaf reports through `onSummary` once its app ships the matching
branch (planninq `feat/projects-leaf-summary`, shillinq and filinq `feat/leaves-library-card`). A leaf that does not report
yet draws nothing in the card.
