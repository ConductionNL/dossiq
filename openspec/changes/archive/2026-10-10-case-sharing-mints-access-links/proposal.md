---
kind: code
depends_on: []
---

# Proposal: case-sharing-mints-access-links

## The rows this closes

**3.19**, area External collaboration, rated `partial`: an external partner
gets a task on a case without an account. The ledger note says dossiq answers
it through `ExternalConsultationResponse`, a token page.

**4.10**, area Documents, rated `partial`: share one file from the dossier
with an outsider, with an expiry. The ledger note says `caseShare` carries the
expiry but shares the whole case, never one document.

**2.21**, area Participation, the portal half: a citizen proposes a change to
their own case. The comment a link holder writes is the channel that half
needs, and portaliq owns the queue in front of it.

## Why the token page never worked

The token page is unreachable, and has been since it was written.
`AdvisoryBodyService` says so in its own source:

> `issueSecureToken()` minted a 32-byte hex token and wrote it onto a
> consultation object as `secureToken` [...] Neither had a caller. The read
> half IS live [...] Because nothing ever minted one, that public surface can
> never be entered.

So dossiq shipped three files for a feature nobody can use: a public
controller, a Vue page and a route. Nobody noticed, because an advisory body
that never receives a link never reports a broken one.

The public case link had the mirror problem. It resolves through OpenRegister's
shares leaf, which serves whatever the public group may read. An advisory body
cannot answer through it, because the leaf grants reading and nothing else.

## What we take instead

OpenRegister merged `access-by-link-not-by-account` as #3817. A link names one
subject, declares what its holder may do, carries an expiry, and may carry a
password. Reading is always granted. Commenting and uploading are granted only
when the link says so, and an undeclared capability answers 403. Every use is
written to the audit trail with the link as the actor, `link:<uuid>`, so four
uses of one link are four entries naming that link.

That is the whole of what dossiq was trying to build three times: once for the
case token, once for the consultation token, once for the per-file share.

## What changes

A case share mints an access link. The handler picks what the outsider may do,
until when, and whether a password is needed. The share stores the link's id,
its uuid and the URL to send, and revoking the share revokes the link.

An external consultation rides that link's `comment` capability. The advisory
body is named on the share. The advice arrives as a comment written by the
link, and dossiq collects it onto the consultation as the response, naming the
body it came from. The token page, its controller and its two routes are
deleted.

A document named on the share mints its own `file` link, so an outsider who
needs one report does not receive the dossier.

The Sharing tab lists every link on the case with its state and a revoke
button, and shows the handler what the outside sees before they send it.

## Ownership

OpenRegister owns minting, expiry, revocation, the password check, the 404 that
covers unknown, revoked, paused and expired alike, and the projection that
decides what a holder reads. Dossiq owns which subject is published, which
capabilities a case share declares, and how the advice comes back onto the
consultation.

## Size

M. One new service, one consultation service, three endpoints, three deletions.

## The existing spec this extends

`openspec/specs/case-share-via-shares-leaf/spec.md`. Its three requirements
stand: dossiq still mints no token of its own, the bespoke controller stays
gone, and partner handover stays in the zaak domain. This change adds four
requirements beside them.

## Out of scope

The portal queue for 2.21 belongs to portaliq. Federated shares keep their own
path. The Files tab keeps its own sharing.
