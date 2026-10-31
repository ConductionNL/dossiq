# Proposal: seed-cases-once

## Why

`CaseFlowSeedDataRepairStep` looked its demo cases up by title on every repair and upgrade and created any it could not
find. A demo case an operator deleted came back, a purge of the demo residue did not stick, and a case whose title lookup
missed was created twice ("Dakkapel Kerkstraat 12").

## What changes

- The demo cases are planted only when the instance opted into demo data (`demo_data_decided` = `installed`), and once per
  seed version: a complete run records the version in `case_flow_seed_cases_version`.
- A run that failed part-way records nothing, so the next run finishes it.
- The case type and its statuses are still completed on every run.
