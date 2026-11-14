# organisation-edit

## Why

Board dossiq/DqTenant draws "Bewerken" in an organisation's header. An organisation is not a register object, so the
schema-driven `open-form` could not edit it. nextcloud-vue's endpoint form mode (open-form-endpoint) can: it reads the
record from an endpoint and sends the declared fields back.

## What changes

- An Edit header action on the organisation page: an `open-form` over `GET/PATCH /apps/openregister/api/organisations/{uuid}`
  (the record at `organisation`), with Name and Description.
- The Pencil icon is registered.
- Dutch strings.

## Depends on

nextcloud-vue `feat/open-form-endpoint` (manifest schema 2.135.0).
