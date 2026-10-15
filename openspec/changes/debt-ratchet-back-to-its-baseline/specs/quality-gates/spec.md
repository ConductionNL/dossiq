## ADDED Requirements

### Requirement: A suppression that suppresses nothing is not debt to record (REQ-QG-DR-1)

A suppression counted by `scripts/debt-ratchet.js` SHALL hide a finding that the gate would
otherwise report. An inline suppression for an issue class that `psalm.xml` already suppresses
app-wide, or for a PHPMD rule that does not reach the construct it sits on, SHALL be removed
rather than recorded in `.debt-baseline.json`, and the reason the code is acceptable SHALL stay
in a comment at the site.

#### Scenario: An inline tag for a blanket-suppressed psalm issue class is removed
@e2e exclude static analysis; covered by `composer psalm` over lib/

- **GIVEN** `psalm.xml` suppresses `UnusedClass` for the whole app
- **WHEN** a class also carries `@psalm-suppress UnusedClass`
- **THEN** the inline tag SHALL be removed
- **AND** `composer psalm` SHALL report no `UnusedClass` for that class

#### Scenario: A PHPMD suppression that changes no finding is removed
@e2e exclude static analysis; covered by `composer phpmd` over lib/

- **GIVEN** a `@SuppressWarnings(PHPMD.<Rule>)` annotation
- **WHEN** `composer phpmd` reports the same findings with and without it
- **THEN** the annotation SHALL be removed
- **AND** `.debt-baseline.json` SHALL NOT be raised for it

### Requirement: A baseline number rises only for a named, reasoned suppression (REQ-QG-DR-2)

`.debt-baseline.json` SHALL be raised only by the number of suppressions that are kept, and each
kept suppression SHALL carry a reason at its site and be named in the pull request that raises the
number. A counter at or below its baseline SHALL NOT be touched.

#### Scenario: The ratchet holds after the raise
@e2e exclude static analysis; covered by `npm run check:debt-ratchet`

- **GIVEN** seven suppressions added above the baseline, three of which hide nothing
- **WHEN** the three are removed and the baseline is raised by the remaining four
- **THEN** `node scripts/debt-ratchet.js` SHALL exit 0
- **AND** `psalm.inlineSuppressions` SHALL hold at 253, the number it had before
