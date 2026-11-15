---
kind: code
---

# Proposal: saved-views-default-views

## Summary

nextcloud-vue's `saved-views-manage-and-defaults` lets an index page ship
default views (`config.defaultSavedViews`), read-only for users and copyable.
dossiq declares them on its two case lists. Generic by what they filter, not
by procedure:

- Cases: "Mine, overdue", "Mine, due this week", "Unclaimed, overdue". Each is
  two existing quick-filter chips combined, which a chip alone cannot do.
- Queue: "Late", "This week". The Queue's base filter is already open and
  unclaimed; the defaults add the deadline window.

## Depends on

nextcloud-vue `saved-views-manage-and-defaults` (the `defaultSavedViews` key).
Until a release carries it the installed schema lags and `validate-manifest`
reports the key as a library lag, not an error; the vendored schema carries it.
