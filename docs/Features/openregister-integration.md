# OpenRegister Integration

The OpenRegister integration is the foundational data layer for Dossiq, storing all case management data in OpenRegister's flexible register/schema/object model.

## Overview

Dossiq uses OpenRegister as its data backend rather than maintaining its own database tables. This provides flexibility, auditability, and interoperability with other OpenRegister-based applications.

## Architecture

- **Register** -- A single OpenRegister register holds all Dossiq data.
- **Schemas** -- Separate schemas define the structure for cases, tasks, roles, decisions, results, case types, and status types.
- **Objects** -- Individual cases, tasks, and other entities are stored as OpenRegister objects.

## Configuration

The integration is configured through the Settings > Configuration page:
- Register ID
- Schema IDs for each entity type (case, task, status, role, result, decision, case type, status type)

## Features

- **CRUD operations** -- Full create, read, update, delete operations on all entity types.
- **Audit trail** -- OpenRegister provides automatic audit logging of all changes.
- **File attachments** -- Case documents stored via Nextcloud Files, referenced from OpenRegister objects.
- **Search** -- Full-text search across case data via OpenRegister's search capabilities.
- **Validation** -- Schema-based validation ensures data integrity.
- **API access** -- All data accessible via OpenRegister's REST API and MCP protocol.

## Status

This feature is implemented and actively used as the core data layer for Dossiq.
