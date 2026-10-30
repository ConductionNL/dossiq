# example-set-decided-terms Specification

## Purpose
What dossiq does for example set decided terms. The requirements were first written by the change `example-set-decided-terms` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-example-set-decided-terms/`) and cover: The example set decides terms in the current quarter.

## Requirements

### Requirement: The example set decides terms in the current quarter

The Zuiddrecht set SHALL hold at least eight completed statutory terms that started within the last 25 days, each on
a case that closed (status Afgehandeld) the day the term was completed, over at least five case types, exactly one of
them completed after its end date.

#### Scenario: DqTermijnen on a fresh demo

- **WHEN** the set is installed and the reader opens Termijnbewaking
- **THEN** "Op tijd beslist" reads a share (7 of 8, 88 %) and "Op tijd beslist per zaaktype" draws five bars
