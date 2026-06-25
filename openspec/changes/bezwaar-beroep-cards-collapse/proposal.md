# Proposal: bezwaar-beroep-cards-collapse

## Summary

Collapse the **Bezwaar & Beroep** group (`BezwaarBeroepGroup`) in the procest navigation into a single top-level menu item that links to a new card-grid landing page. Each of the four former child leaves (`Bezwaren`, `Beroepen`, `BezwaarDecisions`, `BezwaarAdviceRequests`) is rendered as a card on that landing page. All former leaf page routes remain registered and reachable as deep links; only the navigation nesting changes. This change follows the ADR-044 "Menu architecture" cards-collapse rule.

## Motivation

The Bezwaar & Beroep section currently expands into four peer sub-items in the sidebar navigation (`Bezwaren`, `Beroepen`, `BezwaarDecisions`, `BezwaarAdviceRequests`). This is a textbook case for the ADR-044 cards-collapse pattern: the sub-items are a flat list of peer views with no meaningful hierarchy between them, and displaying them as an expanded group consumes sidebar space while offering no additional orientation value. A card-grid landing page communicates the available views at a glance, reduces visual noise in the nav, and preserves full deep-link access to every former leaf.

## Affected Projects

- [x] Project: procest
