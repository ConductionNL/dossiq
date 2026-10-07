---
kind: config
depends_on: []
---

# Proposal: edit-lock-on-the-case-page

Competitor gap register, row 2.27 "Edit lock on a case, with the holder
named" (`procest/_gaps/gap-register.md` in ConductionNL/market-intelligence,
2026-09-13). Rated no, owner openregister, slug `edit-lock-on-the-case-page
(dossiq)`, size S. This is dossiq's half; the lock is OpenRegister's.

## Why

Two caseworkers silently overwrite each other. The register's note says
it plainly: "a real failure mode we have no answer to". `locked` is a
built-in field on every object (`openspec/architecture/adr-000-data-model.md`),
and today no lock has ever blocked a write; openregister's
`run-scoped-object-locking` change makes the lock real and refuses a
person with the holder named.

The best competitors in the register: GLPI 11 `src/ObjectLock.php:47`;
osTicket `include/class.lock.php:21` with the owner shown
(`_round4/compare/promoted-rows-batch3.md`).

## What changes

- Opening the case edit form takes the platform lock; saving, cancelling
  or leaving the page releases it.
- The case header shows "Being edited by <name> since <time>" while
  another holder has it, and the Edit action is disabled with that
  message.
- A refused write shows the holder's name from the platform's refusal.
- Lock lifetime and expiry are the platform's; dossiq keeps no timer.

## Ownership

dossiq builds the take, release and two displays. It consumes openregister
`run-scoped-object-locking` (change exists on openregister `development`).

## ADRs

- Company ADR-022: the lock primitive is OpenRegister's.
- Company ADR-105: the refusal reaches the form as a 423 with the holder
  in `message`.

## Capabilities

- Modified: `case-management`: an edit takes a lock and names its holder.

## Impact

`src/views/cases/` edit form open and close; the case header widget;
`tests/vitest/`; one e2e spec with two sessions. No PHP.
