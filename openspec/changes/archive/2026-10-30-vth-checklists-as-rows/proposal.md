# vth-checklists-as-rows

## Why

On the VTH admin tab (board dossiq/DqVthInstellingen) each inspection checklist was a bordered box with an "Actief"
badge and Bewerken / Verwijderen buttons. The board draws plain list rows: the name over its version and size, the state
as a pill on the right. The badge drew on the solid success colour; under the Zuiddrecht theme the text took the main ink,
rgb(27, 28, 29) on rgb(30, 107, 42), 2.59:1, which fails WCAG AA (4.5:1). The library's success pill is
rgb(30, 107, 42) on rgb(231, 243, 234), 5.77:1, measured on :8080.

## What changes

- The checklists render as nextcloud-vue `CnObjectRow` rows with a `CnStatusBadge` pill (success while active, neutral
  while inactive), separated by a hairline.
- Edit and Delete move to the row's `CnRowActions` menu; their handlers are unchanged.
- The own badge styles go.
