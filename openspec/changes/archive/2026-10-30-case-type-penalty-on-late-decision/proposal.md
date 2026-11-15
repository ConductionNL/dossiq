# case-type-penalty-on-late-decision

## Why

Board dossiq/DqZaaktype's Termijnen card reads "Dwangsom bij niet tijdig beslissen: Ja, na een ingebrekestelling". The
case type had no field for it, so the card could not say whether a late decision costs a penalty (Wet dwangsom bij niet
tijdig beslissen).

## What changes

- caseType 1.33.0 (fragment `99-case-type-penalty-on-late-decision.json`) gains `penaltyOnLateDecision` (boolean) and
  `penaltyMaxAmount` (number, euros, optional). Generic: any case type can say it; no procedure is named in code.
- The terms endpoint answers `penalty` as a sentence: "Yes, after a notice of default[, at most € n]", "No", or empty
  when the case type does not say.
- The Termijnen card shows it after the suspension; the Terms data card edits both fields.
