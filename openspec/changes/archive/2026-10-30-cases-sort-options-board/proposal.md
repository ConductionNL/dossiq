# Proposal: cases-sort-options-board

## Why

Board DqZakenSorteren draws the side panel's "Sorteren op" with Termijn chosen, over Zaak, Behandelaar and Laatst
gewijzigd, and the line "Nu: op termijn, oplopend". Live offered only Urgentie and Nieuwste: the list's own deadline
order matched neither, so the select stood empty and the line named the raw field ("Huidige volgorde: deadline,
oplopend").

## What changes

The Cases page's `sortSelectOptions` start with Deadline (deadline), Case (title), Handler (assignee) and Last changed
(@self.updated, newest first), before the existing Urgency and Newest. Configuration only, existing l10n keys.

The board also lists Zaaktype and Status. Both fields hold a reference, so sorting on them orders by id, not by name;
they are left out until the list can sort a reference by its name.
