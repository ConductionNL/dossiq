# Proposal: publication-category-from-decision-type

## Why

A published Woo decision is filed in OpenCatalogi under one information
category. Until now that category came from a fixed table in code
(`WooCategoryMapper`), which put every decision under `infocat014`
(Woo-verzoeken en -besluiten). A municipality that publishes decisions of
another kind (a subsidy decision, a permit) could not file them anywhere else
without a code change. The category belongs to the decision type, which is
configuration (decision 182, Q-R8-1).

## What Changes

- The `decisionType` schema gains `publicationCategory` (`code`, `label`,
  `uri`), register 0.20.98, `decisionType` 1.3.0.
- The shipped Woo decision type carries `infocat014` as its category.
- `PublicationCategory::forDecision()` replaces `WooCategoryMapper`: the
  category of the decision's type when that row carries a complete one,
  otherwise `infocat014` as before.
- A read of the decision type that fails (OpenRegister refusing or erroring,
  not a missing row) fails the publish, like every other read the publish
  makes, instead of filing the decision under a category nobody chose.
- A repair step copies a shipped category onto a stored decision type row
  that has none, so an existing instance keeps filing its decisions where it
  did.

## Out of scope

- The renames of the publication classes in this branch carry no behaviour.
