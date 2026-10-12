---
kind: code
---

# Proposal: portal-permits-as-held-products

## Summary

Give a resident's parking permits to the portal as products they hold. When a permit case ends with a granted decision, dossiq records a `permit` object for the resident with its title, theme, validity and the details that matter (licence plate, address). Dossiq's citizen contribution declares those permits as a collection of `kind: products` tagged with the theme `parkeren`, with an update action "Kenteken wijzigen" that opens a change request. Portaliq renders it on its ThemaOverzicht page as "Mijn parkeervergunningen".

Rows covered: portaliq `dem-rm-my-products` (reopened by decision 105, 8 October 2026). This is dossiq's half of portaliq's `life-domain-theme-pages`.

## Why

Portaliq's `life-domain-theme-pages` says: "portaliq builds no product register. It renders the products a domain app contributes as a collection with `kind: products` (dossiq for parking permits), with their validity, on the theme page and on a full list per theme. The domain app owns the records and their dates." Its design names the contract: a collection MAY declare `theme`, `kind: products`, `titleField`, `validFromField`, `validUntilField`, `metaFields` and `countLabel`, and a product's actions are the contribution's row actions, such as "Kenteken wijzigen" as an `update` action.

The ThemaOverzicht board (canvas `5NkFW28vZUUij43xzxHg5a`) draws "Mijn parkeervergunningen", "2 vergunningen", a row "Bewonersvergunning binnenstad" with "Geldig", "Kenteken GZ-482-K · Lindelaan 12 · ingegaan op 1 januari 2026", "Geldig tot en met 31 december 2026", and the action card "Kenteken wijzigen".

What dossiq has:

- The citizen contribution (`lib/Portal/CitizenManifest.php`, spec `portal-contribution`) with collections of kind `cases` and `inbox`, scoped by `portalSubject`, and update actions with `citizenWrite`.
- Decisions with `effectiveDate` and `expiryDate` (`decision` schema), linked to a case that carries `portalSubject`.
- No object for a permit a resident holds: a decision is a document about a case, has no subject scope of its own, and carries no licence plate.

## What changes

- **A `permit` schema.** Title, permit kind, theme, holder (`portalSubject`), the case and the decision it came from, `validFrom`, `validUntil`, `status` (`active`, `suspended`, `revoked`), and `details` (licence plate, address).
- **Issued from a decision.** A case type MAY declare `issuesPermit: { kind, theme, titleTemplate, detailsFromCase }`. When a case of that type gets a granted decision, dossiq creates the permit with the decision's dates and the case's details. A revoking decision sets `revoked`.
- **A products collection.** The citizen contribution declares `mijnVergunningen`: register dossiq, schema `permit`, scope `portalSubject`, `kind: products`, `theme` from the permit, title, validity and meta fields as portaliq's contract reads them, and `countLabel` "vergunning" and "vergunningen".
- **Kenteken wijzigen.** An `update` row action on `mijnVergunningen` with one field, the new licence plate, that does not edit the permit: it opens a change case of the case type the permit kind names, so a handler checks it, and the permit's plate changes when that case is decided.

## Out of scope

- Rendering, the "Geldig" tag and the full list: portaliq.
- A product catalogue or Open Product: the decision of 27 September stands.
- Permits other than parking: the schema is general, the seed and the contribution entry cover parking.

## Impact

- Specs: one requirement added to `portal-contribution`.
- New: `lib/Settings/register.d/` fragment for `permit`, the generic `lib/Service/Product/CaseOutcomeProductIssuer.php` (decision 182), called from the existing `DecisionConcludedListener`, seed case type "Parkeervergunning bewoners" with `issuesPermit`.
- Changed: `lib/Portal/CitizenManifest.php` (collection and action), `lib/Portal/PortalContributionProvider.php` (field constants), `caseType` schema (`issuesPermit`).

## Cross-project dependencies

- portaliq `life-domain-theme-pages` renders the collection and the action, and declares the theme `parkeren` on the portal.
