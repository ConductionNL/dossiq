# Tasks: publication-category-from-decision-type

## 1. The decision type names its category

- [x] 1.1 `decisionType.publicationCategory`, the Woo decision type's `infocat014`, register 0.20.98
  - Spec ref: specs/woo-publication-via-opencatalogi/spec.md, "A decision is filed under its decision type's publication category"
  - Files: lib/Settings/dossiq_register.json, lib/Settings/register.d/81-woo-verzoek.json
- [x] 1.2 `PublicationCategory` reads it, falls back to `infocat014`, and lets a failed read fail the publish
  - Files: lib/Service/Publication/PublicationCategory.php, lib/Service/Publication/OutcomePublisher.php
  - Test: tests/Unit/Service/Publication/PublicationCategoryTest.php (incl. `testAFailedReadFailsThePublish`), tests/Unit/Service/Publication/PublicationCategoryDecisionTypeTest.php
- [x] 1.3 Repair step copies a shipped category onto a stored row without one
  - Files: lib/Repair/CopyShippedPublicationCategories.php, appinfo/info.xml
  - Test: tests/Unit/Repair/CopyShippedPublicationCategoriesTest.php
