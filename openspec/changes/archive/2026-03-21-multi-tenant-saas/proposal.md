# multi-tenant-saas Specification

## Problem
Enable logical data isolation for multiple municipalities on a single Procest/Nextcloud deployment. Each tenant has its own users, cases, configuration, and branding while sharing the platform infrastructure. Cross-tenant access is restricted to platform administrators.

## Proposed Solution
Implement multi-tenant-saas Specification following the detailed specification. Key requirements include:
- Requirement: Tenant data isolation via OpenRegister registers
- Requirement: Tenant identity resolution via Nextcloud groups
- Requirement: Tenant-independent configuration per zaaktype
- Requirement: Per-tenant branding via NL Design System tokens
- Requirement: Tenant user management scoped to organization

## Scope
This change covers all requirements defined in the multi-tenant-saas specification.

## Success Criteria
- Tenant-scoped queries return only tenant data
- Tenant-scoped object creation stamps register automatically
- Cross-tenant access returns 404 to prevent information leakage
- ZGW API endpoints enforce tenant scoping
- Database-level query isolation
