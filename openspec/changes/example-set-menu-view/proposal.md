# Proposal: example-set-menu-view

## Why

Board DqFilterInMenu shows Pieter Jansen's own saved view "Termijn deze week" in his menu on the left, with a pin
before it in the side panel and "Uit menu halen" in its menu. The Zuiddrecht set gives Pieter the view but not the
menu entry.

## What changes

Pieter's preference `pref_cn_saved_view_menu` lists the view on the Cases page, quoted as
`{{view:pieter-termijn-deze-week.uuid}}`. Needs OpenRegister feat/example-set-view-placeholder (the placeholder, and
preferences written after views) and nc-vue fix/r25-saved-view-menu-by-uuid (a menu entry by uuid). Data only.
