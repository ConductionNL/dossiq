# The payment state reads as words

## Why
The Termijn en betaling card shows the case's `paymentState` as its stored code ("notRequired"). DqZaak draws "Geen leges voor dit zaaktype".

## What changes
`paymentState` gets `x-enum-labels` (case 1.54.0, register.d fragment), with Dutch entries for the two new labels. The data (a seeded state and when it was checked) belongs to the example set.
