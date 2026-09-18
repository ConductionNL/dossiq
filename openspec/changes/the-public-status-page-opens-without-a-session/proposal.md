---
kind: code
depends_on: []
---

# Proposal: the-public-status-page-opens-without-a-session

## Summary

A citizen holding a live "track your case" link cannot open the page. This
change makes `/public/status/:token` reachable without an account, and gives
somebody a way to hand out the link in the first place.

## What was measured, and what it rules out

Read this before building anything here, because the obvious repair is the
wrong one.

**The data path is already anonymous.** `src/views/public/PublicStatusPage.vue`
reads `GET /apps/openregister/api/public/case-tokens/{token}`, an audited,
RBAC-respecting surface in openregister with its own throttle. The bespoke
dossiq public-share controller and its `/api/public/share/*` and
`/api/public/status/*` routes were REMOVED when that landed, and
`appinfo/routes.php` says so at the site. So there is no dossiq share token to
repair, and rebuilding a token path here would replace a working anonymous
surface with a second one.

**The block is the page, not the data.** The SPA is served by the catch-all
route, which requires an account. A citizen with a live token meets a login
screen before any of the above runs.

**Nothing mints a token.** `CaseTokenService::mint()` exists in openregister and
the resolve route is live, but no dossiq surface calls the mint. So even with
the page open, a citizen has no link to hold. That is the second half of the
promise, and it is a feature rather than a repair.

## What changes

- `src/manifest.json`: `config.mode: "public"` on the three `/public/...` pages.
  The flag alone opens nothing; the `/public/` prefix is the second condition
  and these three already satisfy it.
- `appinfo/routes.php`: dossiq reproduces the AppHost route table rather than
  calling `Routes::standard()`, so it adds `dashboard#publicPage` on
  `/public/{path}` itself, before the catch-all, and its dashboard controller
  needs the `publicPage()` method or the route answers HTTP 500.
- Boot the page alone when the initial state `public_page` is true: no app
  navigation, and none of the stores that fetch authenticated data.
- A surface that mints a case token for a case, so the link can be handed out,
  and the recipient is recorded.

## Depends on

openregister PR #3964, `public-pages-open-without-a-session`: a page opens
without a session only when the app declares it public. Until that engine is on
`parity/round2`, the manifest flag is read by nobody and the page would still
be dark.

## What does not change

- No endpoint answers more than it did. The shell carries no case data.
- The catch-all stays authenticated.
- `case-tokens` stays the anonymous read surface. It is not replaced.
