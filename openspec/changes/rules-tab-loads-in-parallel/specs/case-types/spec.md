# case-types Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [rules-tab-loads-in-parallel](../../)

## Purpose

The Rules tab shows within the time of its slowest read.

## ADDED Requirements

### Requirement: The Rules tab reads side by side

The Rules tab SHALL start the reads of the rule vocabulary, the case schema, the rule inventory, the case type, its
blueprint and its document type, message template and role lists before any of them answers.

#### Scenario: DqZaaktypeRegels

- **WHEN** an administrator opens the Rules tab of a case type
- **THEN** all reads are under way at once, and the rows show once the slowest has answered
