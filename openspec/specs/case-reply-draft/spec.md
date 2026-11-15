# case-reply-draft Specification

## Purpose
What dossiq does for case reply draft. The requirements were first written by the change `case-reply-draft` (archived 2026-10-30 under `openspec/changes/archive/2026-10-30-case-reply-draft/`) and cover: a reader of a case keeps one reply draft on it.

## Requirements

### Requirement: a reader of a case keeps one reply draft on it

GET and PUT /api/cases/{id}/reply-draft MUST refuse anyone who may not read the case before reading or writing anything. A saved text MUST come back to the same user on the same case with the time it was saved, MUST NOT be readable by another user, an empty text MUST clear it, and a text over 20,000 characters MUST be refused.

#### Scenario: interrupted answer
- GIVEN Pieter saved "De stukken zijn verzameld." as a draft on 2026-0082
- WHEN he opens the case again
- THEN the reply box holds the text
