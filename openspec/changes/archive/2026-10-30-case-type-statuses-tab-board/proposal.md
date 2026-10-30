# Proposal: case-type-statuses-tab-board

## Why

The case type's Statuses tab against board DqZaaktypeStatussen: the lists had no line saying what they are for, every
Add read "Toevoegen", the statuses list showed order, role and waiting-on where the board shows list colour, whether a
status ends the case and what the applicant sees, and a result's retention period read "P10Y".

## What changes

- Statuses: subtitle "Order, colour and what the applicant sees. Drag to change the order."; columns Status, Colour in
  lists, Final status, What the applicant sees. Order stays a drag; role and waiting-on stay in the form.
- Results: subtitle "How a case ends, and what happens to the file afterwards"; the retention period through the
  `isoDuration` formatter (nextcloud-vue feat/iso-duration-formatter).
- Add labels: Add result, Add document type, Add decision type (Add status existed); the board-look title-row Add
  reads them with nextcloud-vue fix/catalog-add-reads-add-label.
