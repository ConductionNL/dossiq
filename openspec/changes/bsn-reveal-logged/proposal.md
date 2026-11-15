# Proposal: bsn-reveal-logged

## Why

Board DqContact: "Tonen" beside the masked BSN, and "Het tonen van het BSN wordt gelogd." The coordinator decided
on 24 Oct to make that true with a server-side reveal. Until now the person record carried the full BSN, the page
masked it in the browser, and nothing was logged.

## What changes

- **Schema:** brpPerson 1.7.0 adds `audit: true, reveal: "explicit"` to citizenServiceNumber's authorization, next to
  the existing read right for dossiq-sensitive. Register 0.20.70.
  - With openregister property-explicit-reveal (PR #4843) the objects API answers the BSN as "*****7609".
  - It answers the number only to a read naming `_reveal=citizenServiceNumber`, after the read check, and records
    that reveal.
- **Identity card:** the BSN row's Tonen reads that URL (nextcloud-vue feat/facts-reveal-from-server). The card ends
  with "Het tonen van het BSN wordt gelogd."
- **RequesterProjection:** a masked or unreadable number is not written over the case's `initiatorSourceId`.
  - For the same requester it keeps the stored number.
  - For a new requester it writes '' rather than the mask.
  - This also closes the older gap where a reader without the sensitive right wiped the number.

- dossiq's server reads that need the number run trusted in OpenRegister (`TrustedRevealRead` over
  `ExplicitReveal::trustedDuring()`), because OpenRegister otherwise answers the BSN masked and refuses filtering on it:
  - the requester projection (`initiatorSourceId`);
  - finding a household's people by BSN.
- Known consequence: OpenRegister takes an explicit-reveal property out of free-text `_search`, so the party picker no
  longer finds a person by typing a BSN. A logged lookup by BSN, through dossiq's guarded citizen lookup, is the way
  to offer that again; this is a decision for the coordinator.

## Scope

Stacked on r25/contact-side-cards-from-moments (the same card). Merge only together with openregister #4843 and
nextcloud-vue feat/facts-reveal-from-server. Without #4843 the BSN still arrives in full and Tonen reads it again
with an ignored parameter, so the line would not be true yet.
