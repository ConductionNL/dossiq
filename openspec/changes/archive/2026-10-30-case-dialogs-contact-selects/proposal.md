# Proposal: case-dialogs-contact-selects

## Why

DqZaakDialogen draws Contact vastleggen with Kanaal and Richting as two selects side by side, both marked optional
("(niet verplicht)"). The dialog asked them as two rows of radio buttons, six for the channel and two for the direction,
which made it the tallest of the small case dialogs.

## What changes

- Channel and direction become two selects in one row, labelled "Kanaal (niet verplicht)" and "Richting (niet
  verplicht)", each starting on today's default (phone, inbound) and never empty.
- Every channel and direction stays choosable; the saved values are unchanged.
