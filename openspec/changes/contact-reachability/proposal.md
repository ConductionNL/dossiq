# Proposal: contact-reachability

## Why

Board DqContact, "Bereikbaar via" draws:

- "Mijn Zuiddrecht, berichtenbox" when the person has a portal account;
- the last contact;
- that phone and e-mail are not in the base registration.

The coordinator decided (24 Oct) to build it: portaliq answers whether a person has a portal account (portaliq PR
#1713, read-only, with a permission check), and dossiq shows the row.

## What changes

- `GET /api/people/{id}/reachability` (PersonReachabilityController, PersonReachability):
  - it reads the person under the user's own rights first, and answers 404 when the user may not read it;
  - it asks portaliq's `PortalAccountPresence::forCitizen()` with the person's BSN, read trusted because the BSN is
    explicit-reveal, and never returned;
  - it adds the newest contact moment;
  - it answers `{portal: "Mijn Zuiddrecht, berichtenbox" | "", lastContactAt, lastContactChannel}`.
- The Bereikbaar via card reads that endpoint: the mailbox row (envelope icon), "Laatst contact op 4 oktober, via
  ...", then the registration line (from the person).
- Without portaliq, the row is left out.

## Scope

Stacked on r25/party-dates-longdate and r25/bsn-reveal-logged (TrustedRevealRead). 1 l10n key.
