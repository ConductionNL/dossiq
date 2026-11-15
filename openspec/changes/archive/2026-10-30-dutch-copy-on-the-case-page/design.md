# Design: dutch-copy-on-the-case-page

## Translate once, before the renderer

dossiq already translates lens labels, footer notes, captions and banner copy in
one pass over the built manifest (main.js), because the library draws those keys
as written. Tab labels and section labels are the same case, so they join that
pass rather than getting a translate call inside each component. The labels are
English source strings with entries in the app catalogue, which is what the pass
assumes.

A label that is already Dutch, or has no entry, comes back unchanged, so the pass
is safe on a profile that overrides a label.

## Captions follow the board

DqDashboard draws the My tasks tile as "2 vandaag af" and the SLA tile as
"haalden hun termijn", so those are the Dutch entries.
