# Tasks: a-delivered-set-is-written-by-publishing-only

## 1. No hand-made delivery

- [x] 1.1 `allowCreate: false` on `case-woo-delivered-sets`, and an empty text naming Publish (Woo)
  - Spec ref: specs/woo-publication-via-opencatalogi/spec.md, "A delivered set MUST be written by publishing only"
  - Files: src/manifest.json, l10n/en.json, l10n/nl.json, l10n/en.js, l10n/nl.js
  - Test: tests/vitest/manifestWooDeliveredSets.spec.js

## 2. Open

- [ ] 2.1 Link out to opencatalogi's publish screen (board OcPubliceren, decision 256) in place of the confirm-only action (separate change)
