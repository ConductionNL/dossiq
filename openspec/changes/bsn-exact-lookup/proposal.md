# Proposal: bsn-exact-lookup

## Why

With openregister #4843 a BSN takes no part in free-text search, so the party picker (new case, requester, contact
persons) no longer finds a person by typing a BSN. The coordinator decided (24 Oct) on an exact "zoek op BSN" lookup
through dossiq's guarded citizen lookup, logged, and no free-text search on BSN.

## What changes

- `POST /api/people/lookup { term }` (PersonLookupController, PersonNumberLookup):
  - the guard runs first (`GuardedCitizenLookup`, the KCC rule): a refusal is recorded and answered 403;
  - only a well-formed BSN (nine digits, 11-proef) is looked up;
  - the person is read under the caller's rights, with the filter run trusted (TrustedRevealRead);
  - each answered lookup is recorded;
  - the answer names the person, never the number;
  - rate-limited like the KCC lookup (60 per hour).
- Every person source of the party picker (14 in the manifest and the simple layout, and the initiator picker) has
  `lookup: { url: "/apps/dossiq/api/people/lookup", when: "^[0-9]{9}$" }` (nextcloud-vue
  feat/party-picker-exact-lookup).
- The initiator picker no longer prints the BSN in its second line.

## Scope

Stacked on r25/bsn-reveal-logged (TrustedRevealRead); hold with openregister #4843.
