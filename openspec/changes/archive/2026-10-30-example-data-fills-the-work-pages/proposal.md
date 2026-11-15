---
kind: code
---

# Proposal: example-data-fills-the-work-pages

## Why

The example data the setup wizard loads is generated from the schemas: three placeholder objects per schema. A fresh install that loads it still shows empty work pages. Nothing is assigned to the person who loaded it, no deadline falls this week, no Woo document is assessed and there is no data subject request. The boards (DqMijnWerk, DqAanMijToegewezen, DqDagAfsluiten, DqDashboard, DqMijnTeam, DqBesluit, DqLakken, DqPrivacy) draw a working day.

## What changes

1. `lib/Settings/demo.d/*.json` holds curated example objects, in the shape of a register seed. `DemoDataService` imports them with the generated set, and the offer counts them.
2. `ExampleDataFragments` fills in load-time tokens: `@me` (the user who loads the set; left out when nobody is signed in), `@today±Nd` and `@now±Nd`. Deadlines therefore always fall around the day the set is loaded.
3. The first fragment, `10-work-pages.json`, holds:
   - five Woo requests in different statuses, four of them assigned to `@me`, one overdue and two received today;
   - four documents on one of them, assessed as public, partly public (two) and not public, with citable refusal grounds;
   - an AVG request case type with three data subject requests (inzage, correctie, verwijdering).
   Everything is fictional.

## Not in this change

- Departments: they are Nextcloud groups. An OpenRegister import cannot create groups or memberships.
- File contents for the example documents: the redaction page lists them, but text detection needs a real file.
