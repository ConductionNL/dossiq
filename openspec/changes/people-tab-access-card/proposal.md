# Proposal: people-tab-access-card

## Why

Board DqZaakBetrokkenen ends the Betrokkenen tab with a "Toegang" card: who may open the case, and why. Its rows include "Link voor Sanne de Vries" with an SV avatar, "Alleen lezen, zonder account · de link kan eindigen", a "Verloopt 15 nov" pill and a revoke icon button, under a "Delen" button and over a footnote about the processing log. The simple profile's People tab had no such card. The case's links lived only in the sidebar Sharing tab, so compdiff (r43) paired the board's third SV with the side Aanvrager avatar (40 vs 32).

## What changes

The simple People tab gets a fourth card, `case-access-card`: a nextcloud-vue record list over dossiq's existing link list (`/api/access-links/case/{id}`, the endpoint the Sharing tab reads). Each link is a row with the holder's initials at 32px, "Link for {label}", what the holder may do, and its state or end date as a pill. A live or paused link ends in an icon button that revokes it. "Share" opens the sidebar Sharing tab, which keeps the whole flow (links, partner shares, transfers, federated shares). Config only, no PHP. Needs nextcloud-vue feat/r43-icon-row-action-and-sidebar-tab-header-action (`rowAction.iconOnly`, header action `open-sidebar-tab`); on an older library the revoke button shows its label and Share is absent.

Not in this change: the board's team and group grant rows ("Team Woo en informatie · via het zaaktype", "Juridische zaken · gedeeld door ..."). They come from the Access sidebar tab's permission catalogue, which no endpoint lists as rows yet.
