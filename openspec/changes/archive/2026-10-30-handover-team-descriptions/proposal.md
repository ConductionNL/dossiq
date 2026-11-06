# Proposal: handover-team-descriptions

## Why

Board DqOverdragen lines each team in the hand-over list with its size and what it does: "6 leden · bezwaar, beroep en
Woo-advies". Decided 25 Oct: the member count from Nextcloud plus the team description; Nextcloud keeps no group
description, so it comes from the organisation role that names the group.

## What changes

- `register.d/97-organisation-role-description.json`: organisatieRol 1.5.0 gets an optional `description` (max 200).
  Digest recorded.
- The case-hand-over action's assignee names `describe: { register: dossiq, schema: organisatieRol }`, which nc-vue
  feat/assign-team-option-subline reads (ncGroupId to description). Before that release the key is ignored.
- The example set's descriptions are data (DQ-DATA).
