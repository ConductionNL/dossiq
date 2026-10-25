# No "Volgende stap" in the header beside AI oversight

## Why

COMPDIFF r25b DqZaakAssistent: on the AI oversight tab the what-now card stands aside, and the page put the stage's "Volgende stap" in the header instead (five header buttons, the board draws four).

## What changes

- The intake, in-progress and review stage actions carry `notTabs: ["ai-oversight"]`, as the what-now card does. Next step stays under Meer.

## Impact

`src/menu-layout.simple.json`. Needs nextcloud-vue feat/stage-primary-follows-tab (schema 2.155.0): against an older schema validate-manifest refuses `notTabs` on a stage action, so it lands with that release.
