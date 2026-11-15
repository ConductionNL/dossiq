# Proposal: my-work-team-chips-in-toolbar

## Why

Board DqAanMijToegewezen draws "Werkverdeling van het team" and its hint under the header, and the team's name chips
("Pieter Jansen (u) 7", "Anouk Bakker 9", ...) on the toolbar row before Filter and the view switch. The covering filter
("Inclusief werk waarvoor u waarneemt") shows only as a chip on the Active line. Live drew the name chips in the heading
block and the covering filter as a tab on the toolbar row as well (r25: 7 toolbar controls where the board draws 10).

## What changes

- WorkloadSummaryBar draws a `part`: `all` (as before), `heading` or `chips`; the parts mounted together read the
  workload endpoint once.
- Registry: WorkloadTeamHeading (below-header) and WorkloadTeamChips (toolbar-start) replace WorkloadSummaryBar.
- The my-work view: slots `below-header` WorkloadTeamHeading, `toolbar-start` WorkloadTeamChips, and
  `quickFilterPlacement: active`, so the covering filter is drawn only on the Active line.

Needs nextcloud-vue feat/index-toolbar-start-and-active-only-quick-filters (the `toolbar-start` slot and the `active`
placement). On an older library the name chips are not drawn and the tab stays, so this ships with that release.
