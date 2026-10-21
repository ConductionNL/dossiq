# Proposal: penalty-payment-rows

## Why

Board DqTermijnen draws the annual penalty audit with a table of the forfeited payments: the case, its case type, the
requester, the day it was forfeited, the amount and the payment (Ruben, 21 Oct: Termijnen follows the board). The annual
statement endpoint lists payments by payment reference only, so the page showed three figures instead.

## What changes

A new endpoint, `GET /api/deadlines/reports/penalty-payments?year=YYYY`, lists the payments forfeited in a year, each
named by its case through the chain payment, calculation, term, case. It answers the year's total, count and disputed
count too, also as a one-row list. The page draws the audit card as the board does: a year field with "Audit laden",
the total, the table and the data warnings; the quarterly report card gets its quarter field with "Rapport laden".
The page-level quarter and year filters go.
