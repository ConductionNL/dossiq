# Proposal: menu-board-icons

## Why

The navigation drew other icons than the DqZijbalk board: a clipboard for My work, a full tray for the team queue, a
folder for All cases, a checklist for Tasks, a group for Contacts and an office building for Organisations. The board
draws an inbox, a bulleted list, a briefcase, a ticked box, two people and a building (Material Design Icons).

## What changes

The manifest's menu entries WorkGroup, Queue, Cases, Tasks, Contacts and OrganisationsMenu name the board's icons, and
the registry registers the two that were missing (InboxOutline, FormatListBulleted). Both structure profiles take the
icons from the manifest. Page headers keep their own icons.
