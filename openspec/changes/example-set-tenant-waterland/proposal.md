# Proposal: example-set-tenant-waterland

## Why

DqTenant draws a second tenant, Gemeente Waterland, with:
- its administrator Marieke Visser;
- the mandates she set (Behandelaar, Teamleider, Afdelingshoofd);
- its billing events: a case created, and its refund as a new line.

The board was measured against the default organisation, which has none of these.

## What changes

- Waterland's `tenantUser` record: Marieke, administrator, with two-step sign-in.
- Three `tenantMandate` records signed by her.
- Two `tenantBillingEvent` records: `case_created` for €1.20, and `case_refund` for −€1.20.
- Each points at the organisation `{{organisation:waterland}}`. The organisation and Marieke come from the openregister cast (openregister#4764).

## Not in this change

Each needs code or another app's configuration:
- the usage and quota meters, which are OpenRegister organisation fields an example set cannot set yet;
- the onboarding steps (5 of 7), which are engine tasks keyed by step, while an example-set task's key is fixed by the loader;
- the monthly package fee, which has no billing event type;
- the board's mandate table with amounts, which is a different widget from the mandate list.
