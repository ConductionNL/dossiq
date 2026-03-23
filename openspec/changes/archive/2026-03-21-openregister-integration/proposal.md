# OpenRegister Integration Specification

## Problem
Procest owns **no database tables**. All data is stored as OpenRegister objects in a dedicated `procest` register containing schemas for all entity types. This spec defines how the register and schemas are configured, how the repair step initializes the data model, how the frontend interacts with the OpenRegister API, the Pinia store patterns, cross-entity reference semantics, error handling, pagination, RBAC, cascade behaviors, and performance considerations.
OpenRegister integration is the foundational layer upon which all other Procest features are built.
**Standards**: OpenAPI 3.0.0 (schema format), OpenRegister API conventions
**Feature tier**: MVP (foundation for all features)
**Competitive context**: Most competitors own their data layer directly -- Dimpact ZAC uses PostgreSQL with 89 Flyway migrations, xxllnc Zaken uses PostgreSQL with CQRS event sourcing via RabbitMQ, ArkCase uses JPA/Hibernate with single-table inheritance, and Flowable uses MyBatis with separate runtime/history tables. Procest's approach of delegating all storage to OpenRegister (a separate Nextcloud app) is architecturally unique: it provides schema validation, audit trails, and RBAC without maintaining database migrations, at the cost of being coupled to OpenRegister's API.
---

## Proposed Solution
Implement OpenRegister Integration Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the openregister-integration specification.

## Success Criteria
- Configuration file exists and is valid
- Schema defines required properties for case
- Schema defines required properties for task
- All schemas include type annotations
- Schema count matches slug-to-config mapping
