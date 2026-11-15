# Proposal: case-dialogs-board-titles

## Why

DqZaakDialogen draws the smaller case dialogs as they open. Measured on :8080 (R20/DQ-L10/m/dlg), two of them opened as
the bare create form of their schema: "Partij toevoegen" as "Rol aanmaken" and "Object koppelen" as "Zaakobject
aanmaken". Their fields came in the schema's order with data-model hints ("Nextcloud-gebruikers-id of
contactverwijzing", "Identificatie van het object in JSON"), and both confirmed with "Aanmaken".

## What changes

- add-party: title "Add a party", confirm "Add". Fields in the board's order: name, role, participant, represented
  by, authorised until, description, then the delegate the board does not draw. No hint lines.
- link-object (and the objects card's copy, link-object-from-card): title "Link object", confirm "Link it"
  ("Koppelen"). Fields in the board's order: object type, identification, link to the object, description. Board labels,
  no hint lines.
- The Partijen card's Partij toevoegen keeps the page action's form (it is a copy of it).
