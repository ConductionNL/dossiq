# Requirement headings say "Requirement:"

## Why

Seventeen requirements in eight dossiq specs carry a heading of the form `### REQ-X: Title`.
The capability matrix links to them, but the strict reference check only accepts a
`### Requirement:` heading, so those links read as unresolved even though the requirement and
its scenarios are there.

## What changes

- The seventeen headings that `openspec/parity/capabilities.json` links to get the
  `Requirement:` prefix. The text after the prefix stays the same, so every existing link keeps
  its heading text.
- In `dashboard`, the non-functional section moves to the end of the file and two retrofit section headings become comments, so REQ-DASH-016 and REQ-DASH-017 sit inside `## Requirements` where OpenSpec reads them.
- No requirement text and no scenario changes. Behaviour is unchanged.

## Impact

- `openspec/specs/{dashboard,case-management,task-management,roles-decisions,case-map-overview,prometheus-metrics,stuf-integration,ai-assistance}/spec.md`.
- Matrix rows 1.13, 2.18, 3.7, 7.1, 7.3, 9.8, 10.1, 10.6, 12.4, 12.23 and 13.7 resolve under the strict check.
