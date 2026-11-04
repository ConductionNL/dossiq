# Proposal: deadline-signals-on-the-dashboard

## Why

Board DqDashboard draws the deadlines card as Termijnsignalen: "Te laat, en minder dan een kwart van de termijn over", fewest days first, with the note "Een zaak met 5 dagen van 56 over staat er niet". REQ-SIG-004 still asked for every case due within three days, so a case with five days of a 30-day term left was missing and a case with three days of a 56-day term was listed. Decision 292 made this its own spec and build change; the board is the canon (decision 130).

## What changes

- The case gains a materialised calculation `termSignalFrom`: the day the case enters the last quarter of its term (start date plus three quarters of the days from start to deadline). It is recomputed whenever the case is saved, and the term engine saves the case when the deadline moves.
- The dashboard's `deadlines` widget filters on `termSignalFrom <= today` instead of `deadline <= today + 3 days`, is titled Termijnsignalen with the board's subtitle, says "Geen termijnsignalen" when empty, and its footer reads "Alle termijnen". Its View all opens the cases list on the same filter.
- REQ-SIG-004 follows the board.
- Existing cases get the value with `occ openregister:rematerialise-calculations`.
