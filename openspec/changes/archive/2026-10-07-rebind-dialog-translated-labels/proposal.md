---
kind: code
depends_on: [case-type-rebind, case-type-rebind-property-impact, case-type-version-chain]
---

# Proposal: rebind-dialog-translated-labels

Live audit, 2026-10-07 (H2): in "Change type or version" every case type
option and every status option read "Array", and the dialog said the case
was "in no status".

## Why

`caseType.title` and `statusType.name` are declared `translatable`, so
OpenRegister stores them as a language map (`{"nl": "Ontvangen"}`). A row
read through `ObjectService::searchObjects()` arrives with the map intact.
Three readers cast that map to a string, which is the literal "Array":

- `CaseTypeResolver::mergeKey()` keyed rows by that cast, so every status
  of a case type merged into ONE row. The dialog offered a single status,
  and the case's own status could not be found ("in no status").
- `CaseRebindService` labelled targets, statuses and the journal titles
  with the cast.
- `CaseVersionDiff` and `CaseTypeVersionChain` named statuses and versions
  with the cast, so a version move mapped statuses by "Array".

A sibling bug sat next to it: `CaseVersionDiff::answeredAmong()` read
`case.properties` as a name-keyed map while the register stores a list of
`{propertyDefinition, name, value}`, so a version move never warned about
an answer it was about to drop. `CaseRebindGate` had the same bug and was
fixed in `case-type-rebind-property-impact` (#3325).

## What changes

- `StatusPublicLabels::textOf()` takes the reader's language: that
  language first (`en_GB` also accepts `en`), then Dutch, then the first
  text the map holds.
- New `Support\TranslatedText` resolves a value in the current user's
  language (from the app's `IL10N`).
- The resolver's merge key reads the map language-neutrally (Dutch first),
  so the key does not depend on who is reading.
- The rebind service, the version diff and the version chain label through
  `TranslatedText`.
- `CaseVersionDiff::answeredAmong()` reads the answers through
  `CaseAnswerReader`, which reads the list shape and a legacy map.

## Out of scope

Other `(string)` casts of translatable fields elsewhere in dossiq. They are
not on this dialog's path.
