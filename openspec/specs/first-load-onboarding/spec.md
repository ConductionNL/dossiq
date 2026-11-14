# first-load-onboarding Specification

## Purpose
What dossiq does for first load onboarding. The requirements were first written by the change `case-page-dutch-labels-one-history-tour-first` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-case-page-dutch-labels-one-history-tour-first/`) and cover: The welcome tour comes before the support note (REQ-FLO-001).

## Requirements

### Requirement: The welcome tour comes before the support note (REQ-FLO-001)

A user who has not finished or skipped the welcome tour SHALL NOT see the
support note. The tour SHALL start on their first load. The support note SHALL
show at most once, on a load after the tour is behind them.

#### Scenario: A new user's first load
@e2e exclude {needs a fresh user per run; checked live with a new user on :8098, the gating asserted in tests/Unit/Service/Onboarding/WalkthroughCompletionTest.php and tests/vitest/casePageLabelsHistoryAndTourFirst.spec.js}

- **GIVEN** a user who has never opened Dossiq
- **WHEN** they open it
- **THEN** the welcome tour SHALL start
- **AND** the support note SHALL NOT show on that load

#### Scenario: After the tour
@e2e exclude {same as above}

- **GIVEN** a user who finished or skipped the welcome tour
- **WHEN** they open Dossiq again
- **THEN** the support note SHALL show once, as before
