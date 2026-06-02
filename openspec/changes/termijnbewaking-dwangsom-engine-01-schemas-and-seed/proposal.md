---
kind: config
depends_on: []
chain:
  - termijnbewaking-dwangsom-engine-01-schemas-and-seed
  - termijnbewaking-dwangsom-engine-02-termijn-binding-lifecycle
  - termijnbewaking-dwangsom-engine-03-pause-extension
  - termijnbewaking-dwangsom-engine-04-daily-scan-escalation
  - termijnbewaking-dwangsom-engine-05-ingebrekestelling
  - termijnbewaking-dwangsom-engine-06-dwangsom-calculation
  - termijnbewaking-dwangsom-engine-07-financial-integration
  - termijnbewaking-dwangsom-engine-08-burger-notifications
  - termijnbewaking-dwangsom-engine-09-reporting-dashboard
  - termijnbewaking-dwangsom-engine-10-bezwaar-rest-api
  - termijnbewaking-dwangsom-engine-11-tests-admin-docs
---

# Proposal: termijnbewaking-dwangsom-engine-01-schemas-and-seed

Member 1 of 11 in the **termijnbewaking-dwangsom-engine** chain (ADR-032 decomposition of the original ~198-task giant). This is the foundational `kind: config` member; it has no predecessor. It declares the six OpenRegister schemas and the register template the whole feature consumes, ships seed `TermijnDefinitie` data, and adds an integration test that verifies the materialised schema fields are correct. Every later `kind: code` member depends (transitively) on this member.

## Why

Dutch administrative law (AWB title 4.1.3–4.1.3a, Wet dwangsom bij niet-tijdig-beslissen, Stb. 2009 383) mandates strict decision deadlines and statutory penalties (€23–€45/day, max €1.442 per case). Communes pay out millions annually when deadlines are missed, and the Nationale ombudsman has repeatedly flagged termijn-overschrijding as a structural issue. The whole engine needs a durable, auditable data model before any deadline logic can be built. Per ADR-031 (declarative-first) and ADR-001 (OpenRegister data layer), the data model is declared as register schemas first so all consumers read the same canonical shape.

## What Changes (this member)

1. Declare six OpenRegister schemas: `TermijnDefinitie`, `TermijnInstance`, `TermijnGebeurtenis`, `Ingebrekestelling`, `DwangsomBerekening`, `DwangsomUitbetaling`.
2. Register them via the procest register template (`lib/Settings/*_register.json` + repair-step import per the fleet pattern).
3. Ship seed `TermijnDefinitie` data for Omgevingsvergunning-regulier (56d), Wmo-aanvraag (42d), and Woo-verzoek (28d, custom €15/day regime).
4. Add an integration test verifying the materialised schemas expose the documented properties and that the seed definitions load.

## Impact

- **Affected**: procest (schema declarations + seed), openregister (register template / REST API consumer).
- **Traces to giant tasks**: Task 1 (schemas + seed), Task 2 (OpenRegister integration setup), Task 22 (integration test for OpenRegister storage — schema-materialisation slice only).
- **Standards**: AWB 4:13–4:18, Wet dwangsom en beroep, Archiefwet (append-only `TermijnGebeurtenis`).
