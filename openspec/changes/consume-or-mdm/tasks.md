# Tasks: consume-or-mdm

## Deduplication / Dependency Check

- [ ] **DC01**: Verify the deployed OpenRegister version ships the MDM engine (`x-openregister-quality` / `x-openregister-dedup` schema support + on-save `qualityScore`/`qualityStatus` materialisation, per ADR-045 / OR change `2026-06-23-mdm-foundation`); record the minimum OR version in `appinfo/info.xml` dependencies. Verify against HEAD of OR `origin/development`, not against assumptions.
- [ ] **DC02**: Confirm no procest code performs duplicate matching, merging, or quality scoring today (expected: none — 2026-07-05 audit found zero MDM annotations and no such services); if any is found, remove it in this change rather than leaving a parallel path.

## Annotations (V1)

- [ ] **T01**: Add `x-openregister-quality` + `x-openregister-dedup` to the `case` schema in `lib/Settings/procest_register.json` exactly as specified in `design.md` (identifier/vergunningaanvraagRef exact match, title normalized+levenshtein, blockingKeys caseType). Edit the JSON by hand (no scripting) and re-validate the file with a JSON parser.
- [ ] **T02**: Add `x-openregister-quality` + `x-openregister-dedup` to the `supplier` schema (kvkNumber/iban exact, legalName normalized+levenshtein, kvkNumber `^[0-9]{8}$` format rule).
- [ ] **T03**: Add `x-openregister-quality` + `x-openregister-dedup` to the `partnerOrganization` schema (oin exact, name normalized+levenshtein, contactEmail format rule).
- [ ] **T04**: Document the steward workflow (OR governance views scoped to the procest register; no procest UI) in `docs/admin/` and record the explicit non-adoption of `x-openregister-survivorship` with its revisit trigger (live BRP/KvK feeds).

## Verification Tasks

- [ ] **V01**: After register re-import, the three schemas in OR carry the annotations; saving a case materialises `qualityScore`/`qualityStatus` on the object.
- [ ] **V02**: Creating two cases with the same `vergunningaanvraagRef` + caseType produces a duplicate-candidate pair in OR's Duplicate Candidates view scoped to procest's register (UI check through OpenRegister, not API-only).
- [ ] **V03**: Creating two suppliers with the same `kvkNumber` produces a duplicate-candidate pair; merging them in OR leaves procest's supplier views consistent (surviving UUID renders, no procest error).
- [ ] **V04**: Register import remains idempotent: re-running the repair step does not corrupt `required` arrays or drop the annotations (guard against the union-merge gotcha).
