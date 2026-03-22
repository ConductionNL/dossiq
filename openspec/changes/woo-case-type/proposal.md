## Why

WOO (Wet open overheid) requests are one of the most common case types for Dutch municipalities. Currently, Procest has no pre-configured WOO zaaktype template. This spec adds a ready-to-use WOO zaaktype template with 8-stage lifecycle, WOO-specific intake fields, 4-week deadline with optional 2-week extension, per-document assessment workflow, and weigeringsgrond selection based on WOO Article 5.1/5.2.

## What Changes

1. WOO zaaktype template JSON with 8 ordered statuses, property definitions, document types, decision types, role types
2. Template Library service to load and activate zaaktype templates
3. Template Library API endpoints
4. Document Assessment Vue component for per-document disclosure classification
5. WOO Intake Form Vue component
6. Template Library admin settings tab
7. Route additions

## Impact

- New service, controller, 3 Vue components, 1 JSON template, route additions
- APIs: /api/templates (list), /api/templates/{id}/activate (POST)
- Dependencies: OpenRegister, Docudesk (optional)
