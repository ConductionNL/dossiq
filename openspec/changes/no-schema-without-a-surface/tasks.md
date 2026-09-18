# Tasks: no-schema-without-a-surface

Tier: V1. Kind: code. Row Q11.31. The instrument first, then the triage,
then the retirements.

- [x] 1.1 `tests/Unit/Architecture/SchemaHasSurfaceTest.php` per D-1, with
  `schema-surface.allowlist.json` seeded with every slug it names, so the
  build is green on day one and the ceiling is the count.
  - fixture register for the orphan, the one-hop child, the child of an
    orphan, and the quoted-versus-prose case
  - `@spec openspec/changes/no-schema-without-a-surface/specs/quality-gates/spec.md`
- [x] 1.2 Triage table.

  **The count is 16, not 27.** The proposal's 27 came from a word-bounded
  grep and it was an upper bound by its own admission. Measured
  structurally on 2026-09-18: 159 schemas declared across
  `dossiq_register.json` and `register.d/`, and 16 with no page, no
  deepLink, no quoted reference under `lib/` or `src/` outside
  `lib/Settings`, and no parent with a surface. Eleven of the proposal's
  names are reached after all, most of them through `SchemaSlugMap`, which
  a name grep cannot see. Five names it never had are on the list instead.

  **The retire column is empty, and that is a finding.** Every one of the
  16 traces to an archived change that shipped its schema and its seeds and
  never shipped its page. Deleting the storage would destroy rows on any
  instance that has been filling it since June, and would take the
  capability off the roadmap by accident. `avgIncident` is a datalek
  register and `supplierUser` holds an activation token and an eHerkenning
  level, so both are decisions somebody takes on the record rather than
  sweeps. The honest fate for all 16 is surface.

  | slug | fate | evidence |
  |---|---|---|
  | avgIncident | surface | archive/2026-06-13-sociaal-domein-zaaktypes; personal data, datalek register |
  | case-location | surface | archive/2026-05-11-case-location; promised by openspec/specs/case-dashboard-view; only prose in PdokLocatieserverService |
  | conflictRecord | surface | archive/2026-06-13-mobiel-inspectie-offline; pairs with syncQueue |
  | fieldEvidence | surface | archive/2026-06-13-mobiel-inspectie-offline; EvidenceMetadataService names it in prose only |
  | gezinsplan | surface | archive/2026-06-13-sociaal-domein-zaaktypes; named by two live specs |
  | indicatiestelling | surface | archive/2026-06-13-beschikking-generatie; named by two live specs |
  | jeugdwetZaak | surface | archive/2026-06-13-sociaal-domein-zaaktypes; a case type registered as its own schema |
  | mdoOverleg | surface | archive/2026-06-13-sociaal-domein-zaaktypes; meetings are decidiq's, so adoption or a page |
  | participatiewetZaak | surface | archive/2026-06-13-sociaal-domein-zaaktypes |
  | reIntegratieTraject | surface | open change sensitive-fields-declared already names it |
  | sociaalDomeinAuditLog | surface | open change sensitive-fields-declared; a second audit trail beside OpenRegister's |
  | supplierKpi | surface | archive/2026-06-13-leverancier-zaakportaal-01-schema-foundation |
  | supplierUser | surface | same; holds an activation token and an eHerkenning level |
  | syncQueue | surface | archive/2026-06-13-mobiel-inspectie-offline; nothing enqueues or drains it |
  | toestemming | surface | archive/2026-06-13-sociaal-domein-zaaktypes; the word is ordinary Dutch all over lib/, which is why a name grep cannot answer this |
  | wmoZaak | surface | archive/2026-06-13-sociaal-domein-zaaktypes |

- [x] 2.1 Retire batch 1. Empty by the triage above: nothing on the list is
  dead, so nothing is deleted. Recorded rather than skipped.
- [x] 2.2 Further batches. Same, and the same reason.
- [x] 3.1 Every allowlist entry names its owning change, which is the
  `ownerChange` field D-1 provides for exactly this.
- [x] 4.1 `openspec validate no-schema-without-a-surface --strict`.
- [x] 5.1 The list is empty, and one of the sixteen was never dark.
  `case-location` was reported as having no reader and had one all along:
  `CaseLocationMap` fetches it, scoped to the case, from the Locations
  section of the case page. The slug sits inside an OpenRegister object URL
  and the scanner only looked for a bare quoted slug. A change to build it a
  surface was queued off that report, which is what an instrument that calls
  a working surface dark costs: somebody builds the thing a second time.
  The scanner now reads the URL shape, anchored at both ends so a slug that
  is a PREFIX of another is not carried by its neighbour, and a fixture pair
  covers both halves.
