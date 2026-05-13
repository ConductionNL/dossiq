# Proposal: Multi-Tenant SaaS

## Summary

Enable logical data isolation for multiple municipalities on a single Procest/Nextcloud deployment. Each tenant gets its own OpenRegister register, user groups, configuration, and NL Design System branding while sharing platform infrastructure.

## Problem

Procest currently operates as a single-tenant application with one register, one configuration, and one set of users. Operating separate Nextcloud instances per municipality increases operational overhead. A multi-tenant model enables shared infrastructure with logical isolation.

## Scope -- MVP

**In scope (MVP tier):**
- Tenant entity in OpenRegister with name, OIN, domain, branding tokens
- Tenant-scoped register creation (one register per tenant)
- Tenant resolution via Nextcloud group membership
- Tenant-scoped queries via TenantService middleware
- Tenant admin user management within their own group
- Platform admin tenant provisioning and cross-tenant access
- Per-tenant NL Design System theme token loading
- Tenant resource limits (max users, max storage)
- Shared zaaktype templates (copy-on-activate)
- Tenant provisioning API and admin UI

**Out of scope:**
- Database-per-tenant isolation (separate PostgreSQL schemas)
- Multi-region deployment or data residency
- Billing or subscription management

## Approach

- Create TenantService that resolves current tenant from Nextcloud group membership
- Create TenantMiddleware that injects tenant context into all API requests
- Per-tenant register creation via OpenRegister API
- NL Design System theme tokens loaded per tenant via CSS variables
- Platform admin can switch tenant context via a selector

## Dependencies

- OpenRegister registers as tenant isolation boundary
- Nextcloud group-based user management
- NL Design System tokens for per-tenant branding
