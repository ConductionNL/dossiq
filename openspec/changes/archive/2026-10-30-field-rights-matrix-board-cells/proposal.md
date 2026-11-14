# Proposal: field-rights-matrix-board-cells

## Why

Board DqZaaktypeVelden draws "Wie wat ziet en invult, per rol en status" with heads and cells at the font's normal line
height, centred in their row, body rows padded 11px, and the field name as plain text on the card. Live set them at the
theme's 1.5 line height, at the top of the row, the field name bold on the theme's grey table head: r34 head row 125.5
against 105.5, cell line height 21 against 16.8, weight 600 against 400, background grey against white.

## What changes

- `CaseTypeFieldRightsWidget`: heads and cells at line height normal, vertically centred; body cells padded 11px 12px; the
  field name weight 400 on a transparent ground.
