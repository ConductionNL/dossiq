# case-management Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [handover-team-descriptions](../../)

## Purpose

A team in the hand-over list says what it does.

## ADDED Requirements

### Requirement: The hand-over list reads a team's description from its organisation role

An organisation role SHALL be able to carry a short description, and the hand-over dialog SHALL show it under the team
whose Nextcloud group the role names.

#### Scenario: DqOverdragen

- **WHEN** the organisation role for group "Juridische zaken" carries "bezwaar, beroep en Woo-advies"
- **THEN** the team reads that line, after its member count where the viewer may read it
