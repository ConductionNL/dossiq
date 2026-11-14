# organisation-header-actions

## Why

Board dossiq/DqTenant draws an organisation's header with Opschorten, Bewerken, the buildiq square and Meer, and its
Status card says "Opschorten blokkeert inloggen, de gegevens blijven. Beëindigen staat onder Meer." The page had the
More menu and nothing to suspend or end an organisation with.

## What changes

- Suspend (header, while active) and Resume (header, while suspended) call OpenRegister's organisation lifecycle
  (PUT /api/organisations/{uuid}/suspend and /activate).
- End the organisation (under More, with a confirmation) calls PUT /api/organisations/{uuid}/deprovision.
- Dutch strings.

## Not built (no backing function)

- **Bewerken**: OpenRegister updates an organisation with PUT /api/organisations/{uuid}, but no library form posts its
  fields to an endpoint that is not a register schema (`open-form` creates a register object). It needs an
  endpoint-backed edit form in nextcloud-vue.
- **Historie tab**: an organisation is not a register object, so it has no audit trail. OpenRegister records no
  lifecycle events for organisations to list. The side History card (created, last changed) stays.
