# Proposal: investigation-steps-in-the-case-type

## Why

Board DqSociaalDomein shows an "Onderzoek 2" tab. Ruben decided (23 Oct) that
investigation steps live in the case type, not in a schema of their own.

## What changes

- Melding Jeugdwet gets a list-of-records field `onderzoeksstappen` (kind,
  date, by, withWhom, summary, consentRef, followUp), sensitive and hidden on
  create, so its answer is stored in `case.sensitiveProperties` and only
  dossiq-sensitive readers receive it. Edited in the case form's list editor.
  Package 1.8.0, stacked on DQ-REINT r25/jeugdwet-case-type (the integrator
  merges the Jeugdwet package with DQ-L2's 1.5.0-1.7.0 branches by hand and
  keeps the highest version).
- The "Investigation" tab (nl "Onderzoek") lists the steps from the case the
  page read and counts them (nextcloud-vue `objectRows` / `countObjectRows`).
- The Oosthaven example case carries two steps.
- Package example dates fill tokens inside a JSON-text answer.
