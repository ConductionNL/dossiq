# Design: vth-workflow-configuration-04-leges-config-ui

## Architecture

`kind: code` member (ADR-032). Vue admin UI (ADR-004) over the leges service from member 03. Uses standard Nextcloud components; modals/dialogs live in their own files (modal-isolation). NcSelect fields carry inputLabel (a11y). No DOM data-attribute reads — server data via initial state / API.

## Component Layout

- `src/views/settings/tabs/LegesRulesTab.vue` — list of rule sets.
- `src/components/LegesRuleEditor.vue` — base fee, modifiers (add/edit/delete), exemptions, verrekening, teruggaaf.
- On save: calls the member-03 leges service to version the rule set (old → validUntil=today, new → validFrom=tomorrow).

## Security (ADR-005)

Rule editing is admin-only; the backend versioning endpoint enforces admin authorization. The UI is a settings tab, not a public route.
