# Proposal: status-badge-text-token

## Why

A status pill's text was the status hue mixed 65% into the ink, which does not match the boards' pill text (Ontvangst
17,83,121 live against 35,74,120 on the boards). The base hues feed column headers and map markers, so they stay.
thematiq now names a pill text per hue in the workplace sets (`--nl-color-<hue>-text`, the boards' text, each at AA on
its tint).

## What changes

The status badge sets `var(--nl-color-<hue>-text, <today's colour>)`: the theme's pill text when it names one, today's
65% mix (or the main text on a `-light` colour) otherwise. Column headers and swatches keep `statusColourStyle`.
