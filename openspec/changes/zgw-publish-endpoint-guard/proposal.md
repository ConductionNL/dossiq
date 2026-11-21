---
kind: code
depends_on: []
---

# Proposal: zgw-publish-endpoint-guard

## Summary

The three ZGW Catalogi publish endpoints (`POST /zaaktypen/{uuid}/publish`, and the same for besluittypen and informatieobjecttypen) require the catalogi write scope, and a zaaktype passes the CT-02b publish guard before it is saved as published.

- Decision: Ruben, 2026-10-15, decision 245 (Q-dossiq-L11-5, option 1).

## Why

The publish endpoints saved the type back with `isDraft: false` without any business rule and without a scope. The same publish through a PUT or PATCH runs the CT-02b guard (status types, a final status, a valid-from date), and every other ZTC write requires `catalogi.schrijven`. A client could publish a case type that cannot be used, and a client without write rights could publish at all.

## What changes

1. All three endpoints answer the ZGW permission error (403, code `permission_denied`) when the caller lacks `catalogi.schrijven`. Nothing is read or saved.
2. A draft zaaktype passes `ZgwBusinessRulesService::guardPublishEndpoint()`, which runs the CT-02b guard. A refusal answers 422 with code `publish_validation_failed` and the missing parts in `detail`. A check that could not read the store answers 503 `check-not-completed` (REQ-ZBR-020). Nothing is saved.
3. Besluittypen and informatieobjecttypen get the scope check only: CT-02b is a case type rule.

## Release notes

- Publishing a catalogue type over the ZGW API needs the scope `catalogi.schrijven`.
- Publishing a zaaktype over the ZGW API is refused with 422 `publish_validation_failed` when it has no status types, no final status or no valid-from date.
