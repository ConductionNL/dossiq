# Proposal: next-step-button-as-drawn

## Why

r36 (on nextcloud-vue 2.163.0) measured the "Volgende stap" button in the case page's what-now card with a play-circle icon before the label (button 160.9 wide, label 44 in) on DqSociaalDomein and every DqZaak* board. No board draws an icon there: the button is the label alone (136.9 wide, label 20 in). The icon came from the three `stage-next-step` entries in the simple case page's `primaryActionByStage`, which the library now draws in the card.

## What changes

`src/menu-layout.simple.json`: the `stage-next-step` entries (intake, in progress, review) lose their `icon`. The Lifecycle entry of the More menu keeps its icon. No schema change.
