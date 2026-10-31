# Proposal: priority-card-reads-like-the-board

## Why

Board DqZaak draws the "Prioriteit" card of the case Overview as a short
explanation: the priority as a pill in the title row ("Hoog"), then where it
came from in label-left rows ("Afgeleid: Hoog, uit impact gemiddeld en
urgentie hoog", "Overschreven: Nee", "Ondergrens: Normaal, van ..."), and one
line on how it is set. Live drew a schema data card instead: "Afgeleide
prioriteit / Normaal" with the label above the value and nothing else.

## What changes

- `case-priority-card` in the simple profile becomes a facts card: header pill
  from `priority`, rows for the derived priority (with the impact and urgency it
  came from), the override (reads "No" when there is none, and the reason when
  there is one) and the floor a rule raised it to, plus the line on how it is set.
- Overriding stays on the card. The case's Edit form leaves the priority fields
  out on purpose (it is the create form), and the data card's inline edit was
  the only place to set an override. The card now carries an "Override priority"
  action that PATCHes `priorityOverride` and `priorityOverrideReason` on the case.

## Dependencies

"Overschreven: Nee" needs nextcloud-vue `feat/r24-facts-entry-empty-value`
(facts entry `emptyValue`). On an older library the row is left out while there
is no override, as before.
