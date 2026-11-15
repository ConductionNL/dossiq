---
kind: code
depends_on: [simple-list-and-dashboard, dashboard-my-work-split]
---

# Proposal: landing-views

## Summary

The landing page gets two views behind one switch: My work and My team. The
greeting's switch now changes the view on the page. It no longer opens the
dashboard or the team queue. Both structures, simple and full, get the views.

## Why

Ruben reviewed the landing page on 7 October 2026 (finding H1). He saw a
greeting with a My work | My team switch, a First today card, and an empty area
below it. The switch options were routes: My work opened the dashboard, My team
opened the team queue. Two pages with two headers stood behind one control.

nextcloud-vue 2.66.0 ships page views (nextcloud-vue #1344). A dashboard page
declares `views`, each its own widget grid. A greeting header whose options
carry `view` draws the switch. The chosen view sits in the address and in the
reader's preferences.

## What changes

1. **Full structure.** The landing page (`MyWorkHome`, route `/`) declares two
   views and opens on My work. My work holds the five widgets the page showed
   before. My team holds widgets the dashboard already declares: the shared
   queue, three team counts, two charts and stalled cases. The switch sits in
   the page header.
2. **Simple structure.** The greeting, First today, the four counts, the week,
   the steps, my tasks and continue working move from the dashboard to the
   landing page. The greeting is the page's own grid and draws the switch. The
   My work view holds the rest, plus Cases you follow and My archival reviews.
   My team is the same view as in the full structure.
3. **Dashboard, simple structure.** It shows the full-structure dashboard
   again. It keeps the link to Close out your day.
4. **No reader arrangement on the landing page.** The library keeps a reader's
   arrangement for the page's own grid only, so `userLayout` leaves this page.
5. **Library.** `@conduction/nextcloud-vue` moves to `^2.67.0`. Its
   dependencies are identical to 2.65.0.

## What stays

- The landing page keeps its header in the simple structure. Its header holds
  Your queue, Assigned to me and Close out your day, which the simple menu
  leaves out.
- The dashboard and the team queue keep their pages and menu entries.
- No new data source. Every widget is one dossiq already declares.

## Risks

- A reader who arranged the full landing page loses that arrangement. Per-reader
  arrangement inside a view is a library question, open for Ruben.
- The team widgets are copies of the dashboard's. A spec fails when a copy
  drifts.
