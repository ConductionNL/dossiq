# case-share-via-shares-leaf Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [case-page-answers-in-card-notices](../../)

## ADDED Requirements

### Requirement: The case lists its shares and a refused revoke is a card notice

`GET /api/cases/{caseId}/shares` SHALL list, for a reader who may read the case, every share the case minted as
`{ id, kind, title, meta, status, url, link, revocable }`, `kind` `file` when the share carries documents and `case`
otherwise. A share is revocable by the colleague who created it while it is active or paused.
`DELETE /api/cases/{caseId}/shares/{shareId}` SHALL revoke through OpenRegister only a share of that case, and
answer OpenRegister's refusal as 409 with an error notice.

#### Scenario: Revoke refused

- **GIVEN** a share created by Pieter
- **WHEN** Anouk revokes it
- **THEN** the answer is 409 with the notice "Intrekken geweigerd door OpenRegister: een link wordt ingetrokken door
  wie hem maakte."

#### Scenario: A share of another case

- **WHEN** a handler of case A names the id of a share of case B
- **THEN** the answer is 403 and nothing is revoked
