# Admin Settings Specification

## Problem
The admin settings page provides a Nextcloud admin panel for configuring Procest. Administrators manage case types and all their related type definitions: statuses, results, roles, properties, documents, and decisions. The case type system is the behavioral engine of Procest -- every aspect of how a case behaves (allowed statuses, deadlines, required fields, archival rules) is defined here. The admin settings UI follows a list-detail pattern: a case type list on the main page, and a tabbed detail/edit view per case type.
**Feature tiers**: MVP (admin page registration, access control, case type list, case type CRUD, status type CRUD with reorder, default case type, publish action, general tab); V1 (results tab, roles tab, properties tab, documents tab, decisions tab, case type versioning, import/export)
**Competitive context**: Dimpact ZAC provides per-zaaktype configuration with parameters, mail templates, reference tables, and an inrichtingscheck validation system. xxllnc Zaken supports case type versioning with draft/active states and template-based folder hierarchies. Flowable provides visual CMMN/BPMN modelers for case type design. Procest takes a simpler, form-based approach that is more accessible to non-technical administrators while maintaining ZGW-compliant data structures.

## Proposed Solution
Implement Admin Settings Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the admin-settings specification.

## Success Criteria
- Admin settings page is accessible
- Regular users cannot access admin settings
- Group admin access
- Admin settings page loads with OpenRegister unavailable
- List all case types
