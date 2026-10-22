# Proposal: example-set-dashboard-data

## Why

Three work dashboards drew empty states on the Zuiddrecht example set:

- DqMijnWerk opens on "Vandaag eerst", Pieter's case whose term ends today ("Parkeervergunningen binnenstad, de termijn
  loopt vandaag af").
- DqAanMijToegewezen shows the work Pieter covers for a colleague ("Inclusief werk waarvoor u waarneemt").
- DqPostvak lists seventeen unrouted messages, six of which look like an open case.

## What changes

The Zuiddrecht set gains:

- a statutory term on Pieter's Woo request Parkeervergunningen binnenstad that ends on the install day (its start date,
  28 days back, plus the four-week Woo term);
- a substitution: Pieter covers for Esra Demir from yesterday to six days ahead, for all her work, on leave;
- seventeen intake log entries in the inbox (`outcome: inbox`), from people and organisations of the cast, received over
  the last week. Six name an open case by its number (`{{object:<case>.identifier}}`), so the inbox proposes that case;
  eleven name none.

All dates are relative to the install day.

## Scope

dossiq's Zuiddrecht example-set fragment only.
