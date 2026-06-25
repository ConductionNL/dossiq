---
kind: config
depends_on: []
chain: []
---

# Proposal: sociaal-domein-zaaktypes

**Status:** proposed
**Scope:** procest
**Owner:** Specter Intelligence — Procest team

## Why

Procest is the case-management foundation for Conduction's zaakgericht werken platform (Nextcloud + OpenRegister). It already ships robust public-sector case patterns (VTH, parafering, bezwaar-beroep) but lacks explicit support for the **social domain** (sociaal domein) — a fundamentally different zaakuniversum from VTH with entirely different privacy rules, processing deadlines, and interagency coordination patterns.

The social domain in Dutch municipalities (post-2015 decentralization) encompasses three statutory pillars:

- **WMO 2015** (Wet maatschappelijke ondersteuning) — social support for adults; processing deadline 6+2 weeks
- **Jeugdwet 2015** (Wet op de jeugdhulp) — youth & family support; processing deadline 4+2 weeks; multi-disciplinary case coordination (MDO)
- **Participatiewet 2015** (re-integration & general assistance) — job placement, income support; processing deadline 2 weeks + ongoing re-integration trajectory

Where VTH cases (omgevingsvergunning, toezicht, handhaving) concern *public interests*, social-domain cases concern *vulnerable individuals* and their families. The data processed falls under AVG article 9: **special categories** (medical data, family situation, financial circumstances, sometimes ethnicity, religious belief).

Procest currently has no:
- **Mandatory AVG classification block** to enforce which special-category data is being stored and why
- **Field-level access control** (e.g., only the assigned wijkteam can see case content; other staff see only metadata)
- **Automatic de-identification** when sharing with external parties (zorgaanbieder, CJG, GGD)
- **Statutory retention schedules** (WMO 15 years, Jeugdwet 20 years, Participatiewet 10 years)
- **Multi-disciplinary overleg (MDO) support** — formal multi-professional case conferences with explicit consent tracking
- **Domain-specific entities** (Indicatiestelling, Gezinsplan, ReIntegratieTraject, AvgClassificatie)

## What changes

1. **New zaaktype family (3 main + 5 supporting entities)**:

   | Zaaktype | Statutory basis | Status lifecycle | Wettelijke deadline |
   |---|---|---|---|
   | WmoZaak (wmo-melding) | Wmo art. 2.3.2–2.3.6 | melding → onderzoek → indicatiestelling → beschikking → uitvoering → evaluatie | 6 weeks (onderzoek) + 2 weeks (beschikking) |
   | JeugdwetZaak (jeugdwet-melding) | Jeugdwet art. 2.3, 6.1 | melding → gezinsplan → ondersteuning → evaluatie ± verlengingen | 4 weeks (gezinsplan) + 2 weeks (decision) |
   | ParticipatiewetZaak (bijstandsaanvraag) | Participatiewet art. 18, 31–34 | aanvraag → toetsing (vermogen + inkomen) → beschikking → re-integratie | 2 weeks (toetsen + beschikking) |

2. **New supporting entities (5)**:
   - `Indicatiestelling` — WMO assessment record + advised support type/volume/duration
   - `Gezinsplan` — Jeugdwet family plan with goals, trajectories, evaluations, consent
   - `ReIntegratieTraject` — Participatiewet re-integration pathway with instruments (wage subsidy, training, coaching)
   - `MdoOverleg` — Multi-disciplinary conference with external participant consent tracking & anonymized data sharing
   - `AvgClassificatie` (value type, not entity) — Mandatory classification block: categories, legal basis (AVG art 9.2 exemption), retention period, access restrictions, anonymization-on-share, export limits

3. **Domain-specific controls** (hardcoded guards in queries, not roles/RBAC alone):
   - **Mandatory AVG classification** at zaak creation; save fails without it
   - **Field-level access**: wijkteam-only for content; non-team staff see only metadata + can request FG-audit-mode read
   - **Automatic anonymization** on export to external parties (unless explicit toestemming recorded)
   - **Statutory retention** with automatic vernietigingsvoorstel generation when deadline approaches
   - **Audit logging** of every read-action on special-category data (medewerker-id, timestamp, IP, fields accessed)
   - **Toestemming (consent) tracking** per externe party, per subset of data, revocable, with proof-of-recording

4. **Tier label**: every zaaktype carries `Tier: sociaal-domein` (procest has no numeric roadmap; this label is the domain anchor).

5. **No code, no UI, no controllers, no tests** are added by this change. It is a *declarative* `kind: config` change per ADR-032 — spec deltas + register-shape implications only. Implementation lands in chained code specs.

6. **Cross-app dependencies declared**:
   - `openregister` for RBAC guards, audit-trail, retention scheduling, lifecycle
   - `openconnector` for iWMO/iJW berichtenverkeer with zorgaanbieders; CJG, GGD, UWV, SVB data exchange
   - `docudesk` for beschikking (decision letter) generation from Wmo/Jeugdwet/Participatiewet templates
   - `launchpad` for wijkteam-dashboard (caseload, doorlooptijden, termijn-overwacht)

## Impact

- **Registers added:** 3 new case-type registers + 5 supporting registers (once implemented per ADR-032)
- **Specs added:** 3 capability specs under `procest/openspec/specs/` (one per statutory law: WMO, Jeugdwet, Participatiewet) + 1 cross-cutting spec for AVG & consent infrastructure
- **Code changed:** none in this change. Each spec's implementation is the work of follow-up code chains — typically: (1) register-patch landing the schema, (2) access-control guards in queries, (3) audit-log instrumentation, (4) optional UI decoration
- **No breaking changes** — procest's existing case-management, workflow-engine, bezwaar-beroep continue unchanged. The new zaaktype families sit beside them.

## Out of scope

- PHP / Vue implementation code (deferred to per-spec code chains)
- FG (functionaris gegevensbescherming) audit UI beyond the metadata read (FG-audit-mode access)
- `pii-detection-masking` implementation in openregister (assumed to exist; this spec documents its *use*)
- Municipal privacy impact assessments (DPIA)—the spec documents legal basis but not DPIA authoring
- Detailed iWMO/iJW berichtenverkeer format (openconnector handles the transport; this spec documents the trigger patterns)

## Reviewer gates this change should pass

- **ADR-022** (no parallel storage): every zaaktype is OR-backed; no separate `WmoZaakMapper` or `JeugdwetService` persistence layer
- **ADR-031** (no custom state machines): every status lifecycle declared as `x-openregister-lifecycle`
- **ADR-024** (manifest navigation): every zaaktype discoverable from procest's case-type selector
- **ADR-032**: this is `kind: config` (specs only — no code surface)
- **AVG legal defensibility**: every `avgClassificatie` block includes statutory basis (AVG art 6 + art 9 exemption), retention rationale, and access-restriction hardcoding
- **Wijkteam access isolation**: queries include guards that prevent non-team staff from reading zaak content even if they accidentally get a zaak ID

