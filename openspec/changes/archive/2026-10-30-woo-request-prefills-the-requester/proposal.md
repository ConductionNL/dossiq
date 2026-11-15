# Proposal: woo-request-prefills-the-requester

## Why

In the Zuiddrecht go-live rehearsal on 2026-10-15 a signed-in resident had to type their own name and e-mail address in step 3 of the Woo request ("Uw gegevens"), although the portal knows both. Portaliq now lets an action field declare `prefill: name` or `prefill: email` (portaliq change `action-fields-prefill-from-the-residents-profile`).

## What changes

- The Woo request's `verzoekerNaam` declares `prefill: name` and `verzoekerEmail` declares `prefill: email`, on both doors (from a dossier and without one).

## Not changed

- What the route accepts and stores. The resident can still change both values.
- A portaliq without the `prefill` key drops it and shows empty fields, as before.
