# Delta: admin-settings

## Changes from base spec

### REQ-ADMIN-009 (IMPLEMENTED)
- Created ResultsTab.vue with result type CRUD
- Archival action (retain/destroy) via radio buttons
- Retention period input in ISO 8601 format
- Human-readable period display (e.g., "20 years")

### REQ-ADMIN-010 (IMPLEMENTED)
- Created RolesTab.vue with role type CRUD
- Generic role dropdown with 8 options: initiator, handler, advisor, decision_maker, stakeholder, coordinator, contact, co_initiator
- Multiple role types can share the same generic role

### REQ-ADMIN-011 (IMPLEMENTED)
- Created PropertiesTab.vue with property definition CRUD
- Format dropdown: text, number, date, datetime
- Max length field (number input)
- Required at status dropdown populated from case type's status types
- Optional/required toggle via status selection

### REQ-ADMIN-004 (ENHANCED)
- CaseTypeDetail tabs expanded from 2 (General, Statuses) to 5 (General, Statuses, Results, Roles, Properties)
