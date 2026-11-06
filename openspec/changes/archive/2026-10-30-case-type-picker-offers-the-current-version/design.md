# Design: case-type-picker-offers-the-current-version

## D-1. The rule is declared where the picker reads it

`CnFormDialog.fetchReferenceOptions()` sends the schema property's
`x-relation-filter` as query parameters and ignores field overrides
(`tests/vitest/newCaseCaseTypePicker.spec.js` records why). So the
current-version rule goes on `case.caseType` beside `isDraft: false`.
`supersededBy` is written only by publishing a successor, so "offered" means
"published, and nothing replaced it", the same answer
`isCurrentCaseTypeVersion()` gives. Checked live: the case type collection
with `isDraft=false&supersededBy=IS NULL` answers 25 of 26 rows on :8098,
without Evenementenvergunning v1.

## D-2. The seed is keyed on its slug

The seed already gives each case type a slug, and OpenRegister keeps it as
`@self.slug`. A slug is not translatable and does not change between runs,
so it is the key that makes a rerun find what an earlier run created. The
title lookup stays as the fallback for a seed row without one.
