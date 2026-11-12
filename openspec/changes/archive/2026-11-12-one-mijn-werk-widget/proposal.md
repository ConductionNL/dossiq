# Proposal: one-mijn-werk-widget

## Why

Board DqNextcloudDashboard draws four dossiq widgets on the Nextcloud dashboard: Termijnsignalen, Taakherinneringen, Mijn werk and Stilstaande zaken. Mijn werk summarises the reader's open work ("8 open zaken op uw naam", "5 open taken, 1 te laat, 1 vandaag") with the footer "Naar Mijn werk". The my-work spec was amended to it (dossiq #5182). dossiq still offered My tasks and Overdue cases beside My open work, and My open work listed items rather than counting them.

## What changes

- My tasks and Overdue cases leave the Nextcloud dashboard; their classes are deleted.
- My open work becomes Mijn werk ("My work"): a cases row (with how many the reader holds as substitute) and a tasks row (with how many are late and due today), each linking to My work, and the button "Naar Mijn werk".
- The three other widgets read as the board: Termijnsignalen, Taakherinneringen, Stilstaande zaken.
- REQ-DASH-024 (My tasks through the item API) is removed; REQ-DASH-023 follows the board.

## Not in this change

The board's rows "3 zaken in uw wachtrij" and "1 archiefbeoordeling" need queue sources dossiq does not have yet (team queue, archive review); they are left for a follow-up.
