---
kind: code
depends_on: []
---

# Proposal: notification-labels-and-tour-titles

## Summary

Three things from the cloud check of 8 October 2026.

1. The notification pane in the user settings showed rule keys
   (`caseAssigned`, `substitutionRegisteredForSubstitute`). dossiq now hands
   CnAppRoot a label per rule (`notificationLabels`), in English and Dutch.
2. The settings footer read "dossiq 0.4.47-unstable" on a 0.4.48-beta
   install. The page now provides the installed version as the `version`
   initial state, and webpack defines `appVersion` through the library's
   `appVersionDefine()`, which reads that state in the browser. With a library
   that does not have the helper yet, the build keeps the info.xml literal.
3. Step 2 of 7 of the getting-started tour had no title, so it opened on a
   bare "2 / 7" and a screen reader heard "Step 2 of 7:" and nothing. Steps 3
   and 6 had the same gap. All three get a title.

## Why

A person reads words, not identifiers, and the version they see is the one
that is installed.

## Dependencies

Items 1 and 2 take effect once dossiq is on the @conduction/nextcloud-vue
release that carries `notificationLabels` and `appVersionDefine()`
(nextcloud-vue change `notification-rule-labels-and-runtime-version`). Item 3
works on any library.
