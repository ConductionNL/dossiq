# Multi-Tenant SaaS

The multi-tenant SaaS feature enables Dossiq to serve multiple organizations from a single Nextcloud instance.

## Overview

For SaaS deployments, Dossiq must support multiple tenant organizations, each with their own case data, configurations, and user access controls.

## Planned Features

- **Tenant isolation** -- Strict data separation between tenants.
- **Per-tenant configuration** -- Each tenant can have their own case types, workflows, and settings.
- **Tenant onboarding** -- Streamlined process for adding new tenant organizations.
- **Shared infrastructure** -- All tenants share the same Nextcloud instance and Dossiq installation.
- **Tenant-specific branding** -- Support for NL Design System theme tokens per tenant.
- **Usage metering** -- Track resource usage per tenant for billing purposes.
- **Tenant admin role** -- Separate admin role for tenant-level administration.
- **Cross-tenant sharing** -- Optional controlled sharing between tenants for inter-organizational case handling.

## Implementation Approach

Multi-tenancy is implemented through OpenRegister's register-level isolation, where each tenant gets their own register with separate schemas and objects.

## Status

This feature is defined in the spec at `openspec/specs/multi-tenant-saas/spec.md` and is planned for future implementation.
