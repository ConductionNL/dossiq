# Tasks: the-social-domain-plan-and-its-grounds

Tier: V1. Kind: code. Size L. Rows 5.18 and 14.1.

- [ ] 1.1 `lib/Settings/register.d/50-sociaal-domein.json`: the
  `intervention` with its goal reference, provider, start and target dates,
  state and outcome (D-1, D-2).
  - `@spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md`
- [ ] 1.2 The goal with the observable thing that counts as met (D-3).
  - `tests/unit/Service/CasePlanGoalTest.php`
- [ ] 1.3 The provider as a party in the platform's contact model, not a
  string (D-2).
  - `tests/unit/Service/InterventionProviderTest.php`
- [ ] 1.4 Migrate `gezinsplan.goals` and `deploymentTrajectories` into goals
  and interventions, keeping every string and marking its origin (D-4).
  - `tests/unit/Migration/CasePlanStringMigrationTest.php`
- [ ] 1.5 The plan review: a review date, the reviewer and what changed
  (D-5).
  - `tests/unit/Service/CasePlanReviewTest.php`
- [ ] 1.6 The plan surface on the case: goals with their interventions,
  their providers and their dates, and what is overdue.
  - `tests/vitest/casePlanTab.spec.js`
- [ ] 2.1 The existence projection: for a person, whether an open case exists
  in another domain, which domain and the contact. Nothing else (D-6).
  - `@spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md`
  - `tests/unit/Service/CrossDomainExistenceTest.php`
- [ ] 2.2 Require a ground to be chosen before the lookup runs; refuse it
  without one (D-7).
  - `tests/unit/Service/CrossDomainGroundRequiredTest.php`
- [ ] 2.3 Write the ground, the person, the requester, the moment and what
  was returned to `sociaalDomeinAuditLog`, in the same act as the answer
  (D-8).
  - `tests/unit/Service/SociaalDomeinAuditLogTest.php`
- [ ] 2.4 Answer "what was looked up about me" from that log.
- [ ] 2.5 Name the openregister existence-query slug once that lane opens it,
  and keep dossiq's own projection until then.
- [ ] 3.1 Dutch and English strings for the plan, the intervention states,
  the grounds and the refusal.
- [ ] 3.2 `tests/e2e/the-social-domain-plan-and-its-grounds.spec.ts`: a plan
  with two goals and three interventions, an overdue intervention, a review,
  a lookup refused without a ground and one performed with one;
  `openspec validate the-social-domain-plan-and-its-grounds --type change --strict`.
