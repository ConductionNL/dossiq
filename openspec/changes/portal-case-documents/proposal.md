# Proposal: portal-case-documents

## Why

Portaliq's case screen lists the documents a case app publishes on a
resident's case, with the decision first, and opens each one without the
browser learning where the file lives (portaliq change
`cases-documents-on-the-case`, ConductionNL/portaliq#923, spec
`openspec/specs/citizen-case-documents/spec.md`). The case app decides what is
published. Until dossiq declares it, a resident sees only what they sent
(dossiq#3205).

## What changes

- `mijnZaken` declares `documents: {label: "Stukken", provider: "caseDocuments"}`.
- The portal provider gains `caseDocuments(string $caseId): array`, answered by
  a new `PortalCaseDocuments` service with the publication rule.

## Out of scope

- The resident's own uploads: portaliq lists those itself, by tag.
- An editor for the rule. The rule is fixed in code (final or archived, readable by the
  parties, outgoing or the decision) until someone asks to configure it.
