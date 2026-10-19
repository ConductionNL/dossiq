# Proposal: dossiq-pills-library-tag

## Why

dossiq drew about thirty status, priority and label pills with a shape of its own: full pills at weight 400 to 500,
2px 8px or 2px 10px, 11px to 0.9em. Under the board look the boards draw one tag (13px at 600, 3px 10px, 12px corner,
21px), and nextcloud-vue's tag (`cn-status-badge`) draws exactly that; dossiq's own pills did not follow (COMPDIFF r18:
the Cases status column a full pill at 400 on #fdf2e5). The besluit publication badges had no shape at all: their rules
named `decision-publicatie-panel__badge` while the template used `besluit-publicatie-panel__badge`.

## What changes

Every dossiq tag carries nextcloud-vue's tag classes (`cn-status-badge cn-status-badge--<variant>`) and drops its own
padding, corner, size, weight and line height, so it takes the library's shape in every look: a full pill in the
Nextcloud look, the board tag in the board look. Local rules keep only colour; a coloured tag also sets
`--cn-status-badge-ink`, so the theme's text rule and the board look draw its own ink. The status and priority cells
keep their hue from the status colour palette (inline style) and pass its ink the same way. The besluit badges get the
success, error and default variants.

Not tags, and unchanged: the timeline's filter chips and the duration picker's preset buttons.
