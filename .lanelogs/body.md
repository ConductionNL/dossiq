## What changes

Zuiddrecht example set only (`lib/Settings/example-sets/zuiddrecht.json`).

**Field rights on the regular permit v5 (DqZaaktypeVelden).** The matrix makes one column per group that a rule names. v5 keeps all of its existing read-only rules, and the board gets four columns:

- `vergunningen-intake` (a new group; member Mehmet, the cast's intaker; the v5 Intaker role type points at it) and `zuiddrecht-vergunningen`, as before: welstandsgebied hidden at Ontvangen.
- `zuiddrecht-vergunningen` and `zuiddrecht-toezicht-en-handhaving`, as before: welstandsgebied read only from In behandeling to Geweigerd; bouwkosten and soortBouwwerk read only at Verleend and Geweigerd.
- `zuiddrecht-medewerkers`: bouwkosten, soortBouwwerk and welstandsgebied read only at Verleend and Geweigerd.

The required cells come from Ontvangen's existing required field rules. The group isn't named `zuiddrecht-*`, because that prefix marks the six department groups (`ZuiddrechtDepartmentSubAdminTest`), and this group isn't a department.

**History on v4 (Historie).** There are two seeded audit entries by Lars:

- the variants change, 7 days ago;
- `isDraft` going from true to false, 5 days ago, which the history widget shows as the version being published.

## What doesn't match the board yet

DQ-L8 is amending the board for these:

- The board gets a fourth column, for supervision. The handler column reads "Vergunningverlener, ontvangen, in behandeling, besluit, verleend, geweigerd".
- The column for every employee reads "zuiddrecht-medewerkers, verleend, geweigerd", not "Iedereen, afgehandeld".
- The variants entry shows as "Varianten gewijzigd", not "Variant Met welstand toegevoegd".

## Verified

- `composer check:strict` ran once and passed: 9955 tests, 0 failures.
- The Zuiddrecht, example-set and field rights unit tests passed after the follow-up commit: 167 tests.

Not checked yet: an install on :8080.

This PR was prepared with Claude Code.

🤖 Generated with [Claude Code](https://claude.com/claude-code)


