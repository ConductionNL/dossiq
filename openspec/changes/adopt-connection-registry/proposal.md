# Proposal: adopt-connection-registry

## Why

Dossiq's Integrations page tells an admin the truth about each outside
connection. Nobody else can reuse it, because every part of it is dossiq's
own: the `dossiqIntegration` schema, a seed of twelve rows, and
`IntegrationStatusService` writing those rows by key.

The hydra umbrella change `connection-registry` moves that page into
integriq, which already owns sources, circuit breakers and call logs. Every
app declares its connections in one static file, integriq keeps one row per
connection, and every app shows its own rows on the same page. Dossiq moves
first (REQ-CONN-008), so its page becomes the pattern the rest of the fleet
copies.

## What changes

- New `lib/Settings/connections.json` with the twelve keys the page already
  uses: the same titles, descriptions, order and settings links as the seed.
- The Integrations page reads `integriq/app_connection`, preset to `app=dossiq`
  through its menu entry, and names Integriq as the app it needs.
- The generic Add button is gone. Add integration sends the admin to
  integriq's overview, where a source is linked to a declared connection.
- `IntegrationStatusService` no longer writes rows. A probe sends
  `ConnectionStatusReportedEvent`, a save sends
  `ConnectionRefreshRequestedEvent`, and integriq decides the status.
- The seed `96-integrations.json` is removed. The `dossiqIntegration` schema
  stays in the register for one release with a note naming this change.
- The formatters become `connectionStatus` and `connectionSettingsLabel`,
  the names the contract gives them. The old names stay as aliases.

## Depends on

- hydra `openspec/changes/connection-registry` (the contract, design D2 to D10).
- integriq `openspec/changes/connection-registry` on branch
  `feat/connection-registry`: the `app_connection` schema, the declaration sync,
  both events and their listeners, and the Connections overview.

Until integriq ships those, the page shows the missing-dependency screen or an
empty list, and dossiq sends nothing. No dossiq request fails because of it.

## Out of scope

- Building integriq's side. That lane runs in parallel.
- Removing `dossiqIntegration`. That is a follow-up issue, named in the PR.
- REQ-ADMIN-021, the Required apps section. It stays red for the reason it
  already carries.

## Rollback

Revert this change. The `dossiqIntegration` schema is still in the register,
upgraded instances still hold their old rows, and the revert brings the seed
back for fresh installs.
