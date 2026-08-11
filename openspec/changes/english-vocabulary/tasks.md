# Tasks — english-vocabulary (procest)

Scan: **75 schemas / 315 Dutch properties** across 12 register files, **84 files /
55 classes / 198 methods** — the largest code surface in the fleet.

procest is the app the others wait on: openconnector, docudesk and pipelinq all hold
`zaakId`, and pipelinq also holds `zaaktype`.

## 1. BLOCKING — resolve the Besluit/Decision collision first

- [ ] 1.1 Decide whether `besluit` and the existing `decision` schema ("A formal decision
      on a case") are one concept. `procest_register.json` already declares `decision`,
      `decisionType` and `decisionDocument` **alongside** `besluit` and
      `besluitinformatieobject` — the same concept modelled twice, in two languages.
      Renaming `Besluit` → `Decision` is a **schema merge**, not a rename, and ADR-037
      would resolve it by concatenating list values (the shillinq#485 mechanism).
      Options: (a) deliberate merge with a migration, or (b) `FormalDecision` + keep
      `decision`. This is a human modelling call and nothing else starts without it.
- [ ] 1.2 Whichever option wins, check it against decidesk, which declares a third
      `Decision` (the ADR-005 governance supertype). Slug resolution is instance-global.

## 2. Classify the code layer

- [ ] 2.1 Classify all 55 classes and 198 methods as ZGW protocol-facing (keep) or
      procest's own logic (rename). `createBesluitInformatieObject` posts to a ZGW
      endpoint of that name and stays; `nieuweZaak` is ours. One at a time — a scripted
      rename here previously corrupted prefixes and rewrote `@spec` paths.
- [ ] 2.2 List every register-fragment entry that wires a class by name
      (`handler`/`guard`/`requires`/`preconditions`/`save`/`fallbackGuard`). A renamed
      class stops being wired silently.

## 3. Measure and plan the migration

- [ ] 3.1 Count stored objects across all 75 schemas. Resolve numeric register and schema
      ids through `oc_openregister_schemas`, then read the
      `oc_openregister_table_<reg>_<schema>` shards — matching shard table names against
      the schema title matches nothing and reports zero for every app. Exclude `_deleted`,
      and sum across every register each schema is registered in. Prove the query can
      return non-zero before recording any zero.
- [ ] 3.2 Write the migration. ⚠️ procest's is the most consequential in the fleet: the
      `besluit`/`decision` **merge** (task 1.1) is a data migration by definition — two
      populated schemas becoming one — and `Zaak` → `Case` rewrites the key three other
      apps hold. Neither is a rename that data can sit out.

## 4. Rename app-local vocabulary with statute markers

- [ ] 4.1 Sociaal domein: `wmoZaak`/`jeugdwetZaak`/`participatiewetZaak` → `WmoCase`/
      `YouthActCase`/`ParticipationActCase`, each marked with its act.
- [ ] 4.2 Subsidie and mandaat: `subsidieRegeling`/`subsidieAanvraag` → `GrantScheme`/
      `GrantApplication`; `mandaatRegeling`/`mandateringsBesluit` → `MandateScheme`/
      `MandateDecision`; `beschikking` → `Ruling`; `dwangsomUitbetaling` → `PenaltyPayment`.
- [ ] 4.3 `avgClassificatie` → `GdprClassification` (the AVG **is** the GDPR — this is
      internationalisation, not a statutory carve-out) and `toestemming` → `Consent`.
- [ ] 4.4 Termijnbewaking: `termijnDefinitie`/`termijnInstance`/`termijnGebeurtenis` →
      `DeadlineDefinition`/`DeadlineInstance`/`DeadlineEvent`, and the matching jobs and
      controllers. ⚠️ `termijn` must **not** become a fleet word — hrmq uses it for notice
      periods and decidesk for terms of office.
- [ ] 4.5 Remaining app-local schemas: `klantSentiment` → `customerSentiment`,
      `organisatieRol` → `organisationRole`, `medewerkerRolToewijzing` →
      `employeeRoleAssignment`, `portaalBericht` → `portalMessage`, `adviesAanvraag` →
      **hold** (decidesk declares it too and plans the same English name).
- [ ] 4.6 Property-level: adopt the fleet words — `naam`→`name`, `omschrijving`/
      `beschrijving`→`description`, `toelichting`→`notes`, `onderwerp`→`subject`,
      `organisatie`→`organisation`, `bedrag`→`amount`, `bron`→`source`, `titel`→`title`,
      `niveau`→`level`, the publication pair, and validity boundaries →
      `validFrom`/`validUntil`.

## 5. Rename the code layer

- [ ] 5.1 Rename everything classified as ours in 2.1, updating the fragment entries from
      2.2 in the same commit.
- [ ] 5.2 Re-check every `strtolower()`-compared literal a rename touched — a camelCase
      rename makes such a comparison permanently unsatisfiable and no test fails. Run
      PHPStan, which does catch it.

## 6. Hold the shared slugs

- [ ] 6.1 Do **not** rename `vergadering`, `agendapunt`, `raadsdocument`, `stemming`,
      `raadslid`, `fractie` — openregister declares the same six as its ORI mock. Renaming
      one side leaves two apps with divergent vocabularies for one slug. Escalated.

## 7. The four-app window

- [ ] 7.1 `Zaak` → `Case`, `zaakId` → `caseId`, `zaaktype` → `caseType`,
      `hoofdzaak`/`deelzaak` → `parentCase`/`subCase`, landed together with openconnector,
      docudesk and pipelinq. Not before.

## 8. Verify

- [ ] 8.1 `l10n/nl.json` re-pointed not re-extracted; `check-l10n`.
- [ ] 8.2 Re-run the token-aware scan; residual Dutch SHALL be exactly the ZGW protocol
      surface, the six held ORI slugs, and `adviesAanvraag`.
- [ ] 8.3 Full suite plus hydra gates 46/53/54/55/57/61; `validate-seeds` at or below
      baseline and `validate-registers` PASS.

## Acceptance criteria

- The Besluit/Decision question is decided and recorded before any rename lands, and the
  merge ships with a migration — two populated schemas becoming one is data work, not a rename.
- Stored-object counts measured across all 75 schemas and proven by a positive control.
- No rename produces a schema name already declared in procest or another app.
- All 198 methods individually classified; the protocol-facing set listed and justified.
- Every fragment that wires a renamed class by name is updated.
- The six ORI slugs and `adviesAanvraag` are unchanged, with their blocks recorded.
- `Zaak` unchanged unless all four apps land together.
