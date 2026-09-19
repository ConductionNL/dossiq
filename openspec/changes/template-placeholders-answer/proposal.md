---
kind: code
depends_on: []
---

# Proposal: template-placeholders-answer

## Why

Every email dossiq has sent from a shipped template since 14 August 2026 has
had raw `{{behandelaar}}` in it where the handler's name should be.

On 2026-06-11, `dddb267f` shipped three email templates and a variable map that
answered them, in Dutch: `{{startDatum}}`, `{{einddatum}}`, `{{behandelaar}}`.
On 2026-08-14, `75f578d2` ("translate Dutch vocabulary to English, with
migration") renamed the MAP keys to `startDate`, `endDate` and `handler`, and
did not touch the template bodies. The rename was thorough everywhere it could
see; a template body is a string, so it saw nothing.

Nothing caught it, and nothing could have. `EmailTemplateService::resolve()`
leaves an unanswered `{{name}}` exactly as it found it, on purpose: leaking the
placeholder is better than silently blanking a sentence. The template renders,
the mail sends, the send reports success, and the recipient gets a letter with
template syntax in it. `collectUnresolved()` exists and returns exactly these
names, and nothing asks it about a shipped template.

Measured on `parity/round2` at 3ebff1b2:

- **6 template definitions** are affected: the three in
  `EmailTemplateService::DEFAULT_TEMPLATES` and the three seeded copies in
  `lib/Settings/register.d/35-email-templates.json`.
- **10 broken placeholder occurrences** across them.
- **3 distinct names** nothing answers: `behandelaar` (6), `startDatum` (2),
  `einddatum` (2).

## What changes

- The variable map answers the Dutch names as ALIASES of the English ones, so
  templates already stored on instances — seeded in June, edited since — start
  working again without anyone editing them.
- The shipped template bodies use the canonical English names.
- The catalogue the editor offers lists only the canonical names, so a template
  authored tomorrow does not grow a fourth spelling.
- A drift test fails when any shipped template names a key the map cannot
  answer, comparing the two sets rather than a list written by hand.

## Ownership

dossiq builds all of it. The templates, the map and the renderer are its own.

## ADRs

- Company ADR-105: a failure reaches the caller. A placeholder nothing answers
  is a failure, and the gate is where it is now reported.
- Company ADR-025: both surfaces ship in Dutch and English.

## Capabilities

- Modified: `case-email-integration`: every placeholder a shipped template
  names is one the variable map answers.

## Out of scope

- The dotted `{{case.x.y}}` grammar in `HandlesTemplates`, which is a different
  renderer with a different rule: it blanks an unknown path rather than leaking
  it, and `unanswerablePlaceholders()` already guards the one caller that must
  refuse instead.
- Translating template bodies. The Dutch prose stays Dutch; only the
  placeholder NAMES are at issue.
