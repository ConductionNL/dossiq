# Proposal: the catalogue holds the case types, the Store is the Marketplace

## Why

Ruben (23 October 2026) asked for two navigation changes in dossiq.

1. Case types had their own entry in the Advanced foldout, right beside the Catalogue, whose first folder is Case types. The board DqCatalogus draws the case types as part of the catalogue (the Zaaktypen folder with its domains), and DqZaaktypen opens from there. Two ways to the same page read as two different things.
2. The footer entry and page title "Store" rendered as "Winkel" in Dutch (the library's own catalogue translates `Store`). Ruben wants it called Marketplace / Marktplaats. The Dq boards do not draw the entry under any other word (grep of every Dq board for Winkel, Marketplace and Marktplaats: no hits), so the wording follows Ruben.

## What changes

- `CaseTypesMenu` carries `visibleIf: { "user.isAdmin": false }`. An administrator, who has the Catalogue, reaches case types through the catalogue's Case types folder; the entry is gone from their menu in both structures (simple and full). Someone who is not an administrator cannot open the catalogue (its object types read is admin-only), so they keep the Case types entry: users keep features.
- The Case types page, its route `/settings/case-types`, its `?category=` folders and every deep link, saved view and e2e spec that names it stay exactly as they are. No redirect is needed because nothing moves: the catalogue links to that page, as the board draws it.
- The getting-started tour's case types step points at the Catalogue entry ("Open Advanced, then Catalogue") and says the case types are the catalogue's first folder. It stays optional, so a user without the Catalogue skips it.
- The Store menu entry and page title read `Marketplace`, Dutch `Marktplaats`. The route `/store`, the page id `Store` and the menu id `StoreMenu` stay, so links keep working. The catalogue card "From the Store" keeps its board wording (Uit de Store).
- `docs/Features/besluitvorming-workflow.md` names the catalogue path.

## Not in scope

- The catalogue becoming visible to people who are not administrators. That needs the object types read opened up, which is a decision for Ruben.
