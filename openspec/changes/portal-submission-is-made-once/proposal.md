# Proposal: a portal submission is made once

## Why

Live round 3 on the Zuiddrecht demo (14 Oct): a Woo request started from a dossier took more than ten seconds in dossiq. Portaliq's forward gave up at ten seconds and showed "Opslaan is niet gelukt" while the case was already written. The resident sent again and got duplicate cases (2026-0115, 0116, 0118).

Two causes, both on this side:

1. The portal action endpoint made a new case for every send, even a resend of the same answers.
2. The dossier route linked the dossier before it answered: per dossier item one publication read and one case-object write, then a write on the dossier. The tile route, without a dossier, answers in about six seconds; the dossier route added the link on top.

## What changes

- Portaliq relays the key a form makes when it opens (`Idempotency-Key`, portaliq `action-forward-survives-a-slow-answer`). The Woo request endpoint keeps it per resident and action in a generic ledger (`PortalSubmissionLedger`): a resend with the key of a send that made a case gets that case back (201, same body); a send that comes while the first is still being made gets 409 `submission_processing`; a send that was refused lets the key go, so the corrected resend is a first send. A send without a key behaves as before.
- The dossier is linked to the case by a queued job (`LinkCollectionToCaseJob`) right after the case is answered, not before. The job checks the dossier is still the resident's and writes what the inline link wrote.

Nothing here names a procedure: the ledger is keyed by an action scope, and the Woo endpoint is its first user.
