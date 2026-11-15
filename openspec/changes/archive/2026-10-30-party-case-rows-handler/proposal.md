# Party case rows name the handler

## Why

Boards DqContact and DqOrganisatie write a case row's meta line as "2026-0110 · Woo-verzoek · behandelaar Pieter Jansen", and the organisation's open-case side card as "2026-4833 · behandelaar Mehmet Yıldız". The live rows stop after the case type.

## What changes

- The Zaken card on ContactDetail and OrganisationDetail reads `{identifier}`, `{caseType.title}` and `handler {assignee|user}` as meta parts.
- The open-case side card on OrganisationDetail reads `{identifier}` and `handler {assignee|user}`.
- A case without a handler keeps the other parts.
