# Proposal: cases-default-order

## Why

The cases list's count line says "sorted by deadline" (DqZaken: "gesorteerd op termijn"), but the page declared no
order: the first fetch sent no `_order`, the rows came in creation order, and no column header carried `aria-sort`, so
neither the board's sorted-column look nor a screen reader learned the order.

## What changes

The simple profile's Cases page declares `defaultOrder: "deadline"`. The library sends it as the first fetch's `_order`
and passes it to the table, which marks the Deadline header sorted. An order in the address or a header click still
wins.
