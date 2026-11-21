# organisation-history-tab

## Why

Board dossiq/DqTenant has a Historie tab. An organisation had no history to show: it is not a register object and
nothing recorded its changes. OpenRegister now writes them to the audit trail (organisation-lifecycle-audit).

## What changes

- A History tab on the organisation page, listing the organisation's `organisation.status_changed` and
  `organisation.updated` entries from `GET /apps/openregister/api/audit-trails?object_uuid={uuid}`, newest first, each
  with who and when.
- Dutch strings.

## Depends on

OpenRegister `feat/organisation-lifecycle-audit`.
