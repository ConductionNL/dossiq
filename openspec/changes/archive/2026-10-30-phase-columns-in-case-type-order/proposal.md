# Proposal: phase-columns-in-case-type-order

## Why

DqProcesanalyse "Dagen per fase" draws its phases in the flow's order: Ontvangst, Beoordeling, Advies, Besluit,
Verzending. Live put the columns in the order they first appeared while reading the case types, so Advies, which only
some case types pass, came after Besluit and Verzending.

## What changes

The dwell statistics carry each status's `order`. The matrix orders its columns by the case types' flows: a column goes
before another when more case types that have both pass it first. Columns no flow places against each other keep the
order they first appeared in.
