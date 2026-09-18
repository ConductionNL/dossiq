# Tasks: ai-features-on-the-case-consume-hermiq

Tier: V1. Kind: code. Size M. dossiq's half of hermiq#896, #898, #899, #900 and
#902.

The file paths below say where each task landed. This repo's PHP suite lives
under `tests/Unit/`, and its Vue specs under `tests/vitest/`.

- [ ] 1.1 A case type declares, per AI feature, the surface it appears on:
  `case`, `intake` or `none` (D-1). Features are named by the slug hermiq
  registers them under, so one feature is one thing across the two apps.
  - `@spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-a-case-type-declares-which-ai-features-are-on-and-where-they-appear-req-aic-01`
  - `lib/Settings/register.d/*.json` (zaaktype fragment), schema version moved
- [ ] 1.2 An undeclared feature renders nothing and makes no call.
  - `lib/Service/Ai/CaseTypeAiFeatures.php`
- [ ] 2.1 Read which provider each declared feature uses and where it runs, from
  hermiq's register (D-2). No provider, model or residency is stored here.
  - `lib/Service/Assistant/HermiqAiFeatureClient.php::featureResidency()`
- [ ] 2.2 An absent hermiq reports every declared feature as unavailable, never
  as local.
- [ ] 3.1 A feature that reads a document carries the reference, and a request
  without one is refused before it is sent (D-3).
  - `lib/Service/Ai/CaseAiFeatureGateway.php`
- [ ] 3.2 A refusal is rendered with the feature and the reason named, carrying
  hermiq's own gate name rather than a generic failure.
  - `src/components/case/CaseAiFeaturesPanel.vue`
- [ ] 4.1 Ask hermiq which group an incoming report belongs to, and render the
  count with the near-duplicates beside it (D-4).
  - `lib/Service/Ai/ReportGroupingConsumer.php`
- [ ] 4.2 A group changes no confirmation of receipt: the count owed is read
  from the reports, never from the group.
- [ ] 5.1 Declare a create-only intake tool annotated as hermiq's grant
  requires, and the read tools for the outbound surface (D-5).
  - `lib/Mcp/DossiqToolProvider.php`
- [ ] 5.2 The intake tool files through the creation path the create form
  already uses, with its own validation, and refuses what that path refuses.
- [ ] 5.3 Declare the request catalogue hermiq classifies from.
- [ ] 6.1 Ship the initial prompt library into hermiq and stop owning it
  (REQ-AIC-06).
- [ ] 7.1 Fix the `AuditTrailMapper` stub's return type, which is `object`
  where OpenRegister returns `AuditTrail` (D-6).
  - `tests/Stubs/Db/AuditTrailMapper.php`
- [ ] 7.2 Stop `AiSettingsControllerShapeTest` restating the feature list
  `ConfigKeys` already holds (D-6).
  - `tests/Unit/Controller/AiSettingsControllerShapeTest.php`
- [ ] 8.1 Unit tests: the declaration, the unavailable-not-local read, the
  missing-reference refusal, the grouping render, the acknowledgement count and
  the intake tool's delegation.
- [ ] 8.2 e2e coverage or a reason-bearing exclusion per scenario, per gate 19.
  - `tests/e2e/ai-features-on-the-case.spec.ts`
