# Proposal: case-access-purpose

## Why

Ruben decided (20 October 2026) to build purpose logging: opening a case whose case type processes special categories
of personal data (caseType.specialDataCategories, AVG articles 9 and 10) asks the reader why, and the purpose is kept in
the audit trail with the reader, the case and the time. Then the DqSociaalDomein notice can say "Elke inzage wordt
gelogd met het doel".

## What changes

- `POST /api/cases/{id}/access-purpose` (`{ purpose, note }`) reads the case as the reader, reads its case type, and
  when that names special categories writes an OpenRegister audit trail entry on the case
  (`dossiq.case.access-purpose`, context: reader, time, purpose, note, categories). A case the reader cannot read is
  refused (404), an unknown purpose or an "other" without words is refused (422), a purpose that could not be written
  is refused (503) so the case does not open unrecorded, and a case that asks no purpose writes nothing (200).
- The case page declares nextcloud-vue's `accessPurpose` with the same purposes, so the page asks before it opens and
  does not ask again for the same case in the browser session.
