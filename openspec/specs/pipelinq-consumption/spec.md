# pipelinq-consumption Specification

## Purpose
What dossiq does for pipelinq consumption. The requirements were first written by the change `organisation-open-tickets` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-organisation-open-tickets/`) and cover: an organisation's open pipelinq tickets are read by master entity.

## Requirements

### Requirement: an organisation's open pipelinq tickets are read by master entity

dossiq MUST read a party's open pipelinq tickets through PipelinqGateway by the party's `masterEntityRef`, as the calling user. When pipelinq cannot answer, the endpoints MUST say `available: false`, and the organisation list and page MUST leave the tickets out instead of showing zero.

#### Scenario: the organisation list counts open tickets
- GIVEN organisations with master entities m1 (two open tickets) and m2 (none)
- WHEN the list asks `GET /api/parties/open-tickets?keys[]=m1&keys[]=m2`
- THEN it receives `{available: true, values: {m1: 2, m2: 0}}`

#### Scenario: pipelinq is not installed
- WHEN the list asks for counts
- THEN it receives `{available: false}`, and the Open tickets column is not drawn

#### Scenario: the organisation page lists its tickets
- GIVEN the organisation's master entity m1
- WHEN the page asks `GET /api/parties/open-tickets/list?key=m1`
- THEN it receives the open tickets with their total
