# brp-client-retired

## Why

Standards adapters live in integriq, not in dossiq (ADR-118, hydra design section 5). Dossiq shipped
a Haal Centraal BRP client (`BrpHaalCentraalAdapterInterface`, `HaalCentraalBrpAdapter`,
`LogBrpHaalCentraalAdapter`, `BrpLookupResult`) and a registrar that bound it. Its one caller was
the conflict-of-interest check, which read `persoon['relaties']`. The client never asked the BRP
for a relation field, so that list was always empty and the check has never named a relation. A
dormant client and a silent one gave the same answer as a check that ran: no conflict.

Decision 220 (Ruben, 12 October): the check reads BRP relations through integriq.

## What changes

- `PersonRelationReader` asks integriq's `BrpRelationReader` by class name at runtime. Integriq
  absent, too old or not allowed to ask for relations is "not available". A read that is allowed
  and gets no answer is "unanswered".
- `ConflictOfInterestService` takes that reader. Its answer now says what the relation step did:
  `relationCheck: checked` or `not-available` with a reason. An unanswered read blocks with
  `relatie_onbepaald`. `MandaatCheckService::isAuthorized` carries the same two keys.
- The four BRP client classes, `BrpRegistrar`, the BRP contract fixture and their tests are
  deleted.
- The BRP card says where the read lives now and names `relationFields`, the setting on integriq's
  BRP source.
- The `brpPerson` schema and its seed rows stay (capability `brp-register`).

## Impact

The app config keys `integration.brp.mode`, `integration.brp.baseUrl` and `integration.brp.apiKey`
are no longer read. No schema change, no repair step, no version bump. Needs integriq with the
`brp-relations-read` change for the step to run; without it the step reports `not-available`,
which is what a dormant client reported in silence before.

One behaviour gets stricter. A municipality that switched the old client to `mock` or `test` had a
failed lookup pass as "no conflict". With the read allowed in integriq, a failed lookup now blocks
the decision.
