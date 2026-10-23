# Proposal: flow-page-board-config

## Why

Board DqFlow draws a case type's flow ("Omgevingsvergunning regulier") as a case worker reads it: a palette of the steps
dossiq and its neighbours offer, in four groups; each step a card with its name and the app that does it; Save enabled
on the published flow, with "versie 4 · wijzigingen gelden voor nieuwe zaken" in the path. The live page listed the
whole step catalogue, drew catalogue types over config summaries, locked the flow behind a draft notice and measured an
unrelated flow ("Bezwaar advies"), because no example flow of a case type existed.

## What changes

- FlowDetail config: `nodeLook: card`, `saveAsVersion: true`, `metaParts: ["version"]`, `metaNote`, and
  `paletteGroups` with ten steps in four groups (Dossiq, OpenRegister, Integriq, Filinq) under Dutch labels, with the
  short type each step's pill shows. Every other step stays behind "Meer stappen".
- Deviations from the board, kept honest rather than pixel-equal: "Deelzaak of taak aanmaken" is two catalogue steps;
  "Bericht of e-mail" is OpenRegister's send-email and sits under OpenRegister, because Integriq offers no message step.
- The Zuiddrecht example set ships the flow "Omgevingsvergunning regulier": eight steps placed as on the board (an end
  step "Klaar" in the empty corner, since the engine needs one), switched off until an administrator adopts it.

## Reach

Config and example data. Needs nextcloud-vue `feat/flow-board-palette-groups`, `feat/flow-board-node-card`,
`feat/flow-board-save-as-version` (24t1g) for the keys to take effect, harmless before (unknown config is ignored), and
OpenRegister #4802 (example-set flows) for the example flow to install.
