---
kind: code
---

# Proposal: a-delivered-set-is-written-by-publishing-only

## Why

On 13 October a Woo coordinator looked for the publish step on a Woo request. What they found was "Aangeleverde Woo-set aanmaken": the default Add button of the delivered-sets list on the case. It opens the raw schema form, which asks for a SHA-256 hash and a state.

The guided publish exists. "Publish (Woo)" sits under More and calls `POST /api/cases/{id}/woo/publish`, which refuses `no_publishable_documents` and `pages_unseen`. It shows only to the assignee, and only once the decision has set `wooPublicationStatus` to `ready`. On that case there was no decision yet, so the one publish-shaped thing on screen was the raw form.

A delivered set is the record of what a publish wrote, with the hash of every item. A hand-made one records a delivery that never happened.

## What changes

1. The `case-woo-delivered-sets` list declares `allowCreate: false`. The library draws no Add button.
2. Its empty text names the way in: "Publish (Woo) under More writes the delivered set." (nl: "Publiceren (Woo) onder Meer maakt de aangeleverde set.").

## Follow-up, not in this change

The drawn board `DqPubliceren` shows the publish as a dialog: what is published (the decision and the public documents), the catalogue, the publication date, the information category and a toggle to tell the requester. dossiq's action is still a confirm-only `api-call`. Building that dialog needs a preview of the disclosable set from the server, and is its own change.

## Rollback

Revert the commit.
