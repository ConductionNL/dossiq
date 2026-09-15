# Tasks: the-case-archives-through-openregister

Tier: V1. Kind: code. Size M. Consumer half of openregister's
`archiving-as-a-process-with-sign-off`, merged as openregister#3736 and
openregister#3747. Decision D7. Ledger rows 4.20, 13.31, 13.32.

- [ ] 1.1 Declare the case schema's archival classification so openregister has
  a selectielijst category to look up, beside the `x-openregister-archival`
  retention block that is already there (D-1).
- [ ] 1.2 An architecture test that fails when `ArchivalNominationDeriver` or
  `ArchivalBaseDateResolver` loses the note naming what blocks its removal, and
  fails again if a third class starts doing archiefactiedatum arithmetic (D-1).
  - `tests/Unit/Architecture/StandingArchivalDerivationIsDeclaredTest.php`
  - `@spec openspec/changes/the-case-archives-through-openregister/specs/archief-edepot-handover/spec.md`
- [ ] 2.1 An Archiving tab on the case page rendering `@self._retention`: the
  appraisal, the disposal date, the retention period, the selectielijst row, the
  nomination with its rule and moment, and the outcome (D-2).
  - `tests/vitest/caseArchivalPanel.spec.js`
- [ ] 2.2 An unnominatable case shows openregister's reason and is drawn apart
  from a case with no nomination at all (D-2).
- [ ] 2.3 Recompute with a required reason, offered to an archivist or
  administrator and shown disabled with the role to anyone else (D-3).
- [ ] 3.1 A pending archival reviews section in My Work over
  `GET /archival/reviews/pending`, with no client-side narrowing (D-4).
  - `tests/vitest/myArchivalReviews.spec.js`
- [ ] 3.2 Destroy, retain and transfer from that list, each with a reason, and
  retain collecting the new archiefactiedatum (D-4).
- [ ] 3.3 An answered entry leaves the list without a reload, and an empty list
  reads as nothing to sign off rather than as a failed read (D-4).
- [ ] 4.1 `reviewReminderFrequency` in dossiq's archival settings, writing
  openregister's setting and keeping no dossiq copy (REQ-ARCH-13).
- [ ] 5.1 Leave `DossierZipExporter` alone and check its label describes a
  download (D-5).
- [ ] 6.1 `tests/e2e/the-case-archives-through-openregister.spec.ts` covering the
  tagged scenarios. Written and tagged, not run locally.

## Blocked, and by what

Retiring `ArchivalNominationDeriver` and `ArchivalBaseDateResolver` waits on
openregister answering finality for a provider-mode schema, either as a dynamic
`final` matching the `{from, field}` form `initial` already takes, or as a
finality predicate on `LifecycleActionProviderInterface`. Design D-1 has the
measurement. Until then the two classes stand and say so, which task 1.2
enforces.
