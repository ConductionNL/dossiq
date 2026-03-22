## Why

Email communication currently happens outside the case system, breaking the audit trail. This implements email sending from case context with templates, inbound email linking, email-to-PDF conversion, and threading.

## What Changes

1. EmailTemplate schema in procest_register.json
2. CaseEmailService for sending, template variable resolution, inbound processing
3. EmailComposer Vue component for sending email from case context
4. EmailTemplateAdmin Vue component for template configuration
5. EmailThread Vue component for viewing email conversations
6. Route additions for email sending and template management

## Impact

- New schemas, service, 3 Vue components, route additions
- Dependencies: Nextcloud Mail or direct SMTP, Docudesk for PDF conversion
