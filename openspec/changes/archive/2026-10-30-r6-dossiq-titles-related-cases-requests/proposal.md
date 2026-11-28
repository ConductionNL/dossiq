---
kind: code
---

# Proposal: r6-dossiq-titles-related-cases-requests

## Summary

The round-5 cloud check (10 October 2026, dossiq 0.4.51-beta) found five
things on dossiq pages that say something wrong or ask for something that
does not exist. Ruben approved fixing the copy, the Related cases card and the
request errors.

## Why

1. Every dossiq browser tab reads "Dossiq - Conduction Nextcloud", whatever
   page is open. Five dossiq tabs cannot be told apart, and a screen reader
   announces the same title on every route change. Three page titles in the
   manifest are in Title Case ("My Work", "Workflow Board", "LHS
   Recommendations").
2. On the admin settings page the case type list draws its title as an `<h1>`
   inside the "Case type management" section, whose name is an `<h2>`. Its Add
   button reads "Add Case Type".
3. "Apps dossiq works with" shows lowercase app ids (integriq, filinq, ...),
   not product names.
4. A case's "Related cases" card lists its workflow template, status type,
   case type and a bare uuid ("8afb946b-...") as related cases, with the label
   "Case Type" in Title Case. They come from the library widget's Objects
   group, which lists every object OpenRegister's `/uses` and `/used` return.
5. Request errors on dossiq pages:
   - A case page fires `GET /apps/openregister/api/cases/{id}` and gets 404.
     dossiq's own `CasePlanPanel` issues it (`src/services/casePlanApi.js`),
     for every case, while dossiq only projects a plan onto OpenRegister for a
     caseType with `handlingModel: cmmn`.
   - `GET /apps/dossiq/api/preferences/cn_page_view:MyWorkHome` answers 400.
     nextcloud-vue's page views store the chosen view under
     `cn_page_view:<pageId>`, and the colon is outside the controller's key
     charset.

## What changes

- The router sets the tab title to "<page name> - <server title>" on every
  route (`src/utils/pageTitle.js`, installed in `main.js`). The page name is
  the manifest page title, translated. The three Title Case page titles move
  to sentence case.
- The case type list fills `CnIndexPage`'s `#header` slot with a visually
  hidden `<h3>` and passes `addLabel` "Add case type".
- `Prerequisites::DISPLAY_NAMES` names every declared app by its product name
  (OpenRegister, Integriq, Filinq, Humaniq, Decidiq, Portaliq, Pipelinq,
  Hermiq, Thematiq). The lookup ids, the keys `isInstalled()` is asked about,
  do not change.
- The Related cases card turns the library's Objects group off. It shows the
  typed case relations, a "Parent case" section, and the planned follow-ups.
  A row whose far case has no readable title is left out, never shown as its
  uuid; legacy relations get the far case's title from the server. A click on
  a case row opens that case. The English value of the schema title "Case
  Type" becomes "Case type" (Dutch already reads "Zaaktype").
- `CasePlanPanel` reads the case and its caseType first and asks OpenRegister
  for a plan only when it can hold one: a CMMN caseType, a case that still
  carries a blob, or a caseType it could not read.
- `PreferencesController` accepts a key of the form `cn_page_view:<pageId>`,
  with the page id in the existing safe charset. A colon anywhere else is
  still refused.

## Out of scope

- The presence `DELETE` 405 and the hermiq/buildiq requests (another lane).
- No app id in an `isInstalled()` or `class_exists()` lookup changes.
