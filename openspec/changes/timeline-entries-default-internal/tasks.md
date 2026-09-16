# Tasks: timeline-entries-default-internal

Tier: V1. Kind: config plus one reader. Row 6.15. Waits on openregister
`timeline-entry-visibility` for the flag on the leaves.

- [x] 1.1 Note and contact forms: default internal, toggle (D-1).
  - `@spec openspec/changes/timeline-entries-default-internal/specs/portal-contribution/spec.md`
- [x] 1.2 Outbound writers (Berichtenbox, portal message, beschikking
  delivery) set public; status writer per `publicLabel`.
- [ ] 1.3 `CaseTimeline::publicEntries()` (D-2); provider and
  `PublicStatusPage` read it; unit test.
- [ ] 2.1 `tests/e2e/timeline-visibility.spec.ts`; `openspec validate
  timeline-entries-default-internal --strict`.
