## ADDED Requirements

### Requirement: A saved view sorted by deadline keeps equal deadlines in the order they came in

A saved view whose sort starts with the deadline SHALL sort cases with the same deadline by `receivedAt`, oldest first, as the case list's own deadline orders do.

#### Scenario: DqFilterInMenu "Termijn deze week"

- GIVEN Pieter's cases Parkeervergunningen binnenstad (received 28 days ago) and Bezwaar parkeerboete Havenstraat (received 16 days ago) fall due the same day
- WHEN he opens his saved view "Termijn deze week"
- THEN Parkeervergunningen binnenstad is listed first
