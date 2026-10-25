# Proposal: fee-rules-column

## Why

The fee table in the VTH settings (DqVthInstellingen, Legesregels) showed a fee's type value, its regulation and a raw amount. The board names each rule ("Bouwen, per euro bouwkosten"), cites the article ("Legesverordening 2026, 2.3.1") and says how the amount is worked out ("2,35 % van de bouwkosten, minimaal € 125"). Shillinq's FeeSchedule now carries a name and a calculation (shillinq fee-rule-calculation).

## What changes

- The Rule column shows the rule's name, falling back to its type value.
- The Basis column shows the regulation and the article.
- The Amount column describes the calculation: a fixed amount, a percentage with its minimum, a price per unit, or a refund percentage.
- The formatting lives in `src/utils/feeRules.js` and is registered as table formatters.
