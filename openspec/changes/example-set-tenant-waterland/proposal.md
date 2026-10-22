# Proposal: example-set-tenant-waterland

## Why

DqTenant draws a second tenant, Gemeente Waterland, with:
- its administrator Marieke Visser;
- the mandates she set (Behandelaar, Teamleider, Afdelingshoofd);
- its billing events: the monthly package fee, a case created, and its refund as a new line.

The board was measured against the default organisation, which has none of these.

## What changes

- Waterland's `tenantUser` record: Marieke, administrator, with two-step sign-in.
- Three `tenantMandate` records signed by her, each with its terms: Behandelaar up to € 5.000, Teamleider up to € 50.000 with objections and a warning, Afdelingshoofd unlimited with objections and full enforcement (the `terms` property from dossiq `feat/tenant-mandate-table`).
- Three `tenantBillingEvent` records: the monthly package fee (`subscription_fee`, €450, from dossiq `feat/billing-subscription-fee`), `case_created` for €1.20, and `case_refund` for −€1.20.
- Each points at the organisation `{{organisation:waterland}}`. The organisation and Marieke come from the openregister cast (openregister#4764).

## Not in this change

Each needs code or another app's configuration:
- the usage and quota meters, which are OpenRegister organisation fields an example set cannot set yet;
- the onboarding steps (5 of 7), which are engine tasks keyed by step, while an example-set task's key is fixed by the loader;
