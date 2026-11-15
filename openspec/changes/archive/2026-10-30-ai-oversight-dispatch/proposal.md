# Proposal: ai-oversight-dispatch

## Why

dossiq had a delegation to hermiq's `AiOversightRecordedEvent`, but no surface reported a decision to it, and it named
itself `procest`. So hermiq's AI oversight trail and the case's AI-oversight tab stayed empty, and a suggestion that was
shown and then left was never recorded at all (Ruben, 20 Oct).

## What changes

- The case's AI features panel asks the handler what they do with each answer and each feature run: use it, adapt it
  and use their version, or reject it. Each decision is reported.
- A suggestion nobody acted on is reported as ignored: superseded when a newer one replaces it, closed when the panel
  closes or the page is left, expired after 30 minutes.
- A redaction proposal reports its review: approved as proposed (accepted), approved with edited spans (modified),
  rejected, or superseded by a new proposal.
- `POST /api/ai/record-action` checks that the caller may read the case before it writes, accepts `ignored` with
  `unusedBecause`, and takes the prompt, model, document and suggestion id.
- The delegation names `dossiq` as the origin app and maps `ignored` onto hermiq's `ignored` (status `unused`).

Without hermiq the event class does not exist, the delegation returns false, and dossiq keeps its local audit entry.

## Scope

dossiq only. Needs hermiq with the `ignored` action (hermiq `ai-oversight-subject-access`) for the unused records;
older hermiq refuses them and logs why.
