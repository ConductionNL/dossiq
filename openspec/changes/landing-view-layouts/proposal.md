---
kind: code
depends_on: [landing-views]
---

# Proposal: landing-view-layouts

## Summary

A reader arranges the widgets inside each landing page view and keeps that
arrangement. My work and My team each remember their own layout.

## Why

Ruben decided on 7 October 2026 that a reader's own layout should work inside
views and be remembered per view. landing-views took `userLayout` off the
landing page, because nextcloud-vue 2.66.0 kept a reader's arrangement for the
page's own grid only, and the landing page's widgets all live in its views.

nextcloud-vue 2.69.0 keeps one arrangement per view (nextcloud-vue #1359,
preference key `dashboard-layout.<page>.view.<view>`). The manifest still
decides which widgets a view has; the reader decides where they sit.

## What changes

1. **Library.** `@conduction/nextcloud-vue` moves to the release that carries
   per-view layouts (lockfile and range only).
2. **Landing page.** `MyWorkHome` declares `userLayout: true` again, next to
   the `appId` and `pageId` it already declares, so the arrangement is stored
   under a stable key.
3. **Tests.** `dashboardUserLayout.spec.js` expects the key on the landing page
   and keeps asserting that it carries views, `appId` and `pageId`.

## What stays

The views, their widgets, the simple structure's greeting and the Dashboard
page are unchanged.
