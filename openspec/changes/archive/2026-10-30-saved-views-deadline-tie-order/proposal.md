# Proposal: saved-views-deadline-tie-order

## Why

r39 (DQ-L6, REFRESH-8q) lost DqFilterInMenu's match: on 30 October both of Pieter's cases in "Termijn deze week" fall due the same day, and the view sorted by deadline alone, so live listed Bezwaar parkeerboete Havenstraat (received 16 days ago) before Parkeervergunningen binnenstad (received 28 days ago). The board draws the equal deadlines in the order the cases came in, the rule case-list-tie-order-and-texts set for every deadline order the case list declares. Saved views with their own sort were not covered.

## What changes

- The Zuiddrecht set's five saved views sorted by deadline (Termijn deze week, Wacht op inwoner, Opgeschort, Woo-verzoeken (team) and Pieter's Woo-verzoeken) break a tie on `receivedAt`, oldest first.
- The shipped default view "Data subject requests" does the same.
