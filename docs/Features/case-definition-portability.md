# Case Definition Portability

The case definition portability feature enables exporting and importing case type configurations between Dossiq instances.

## Overview

Organizations should be able to share their case type definitions, workflows, and configurations with other Dossiq users. This feature provides standardized export/import capabilities.

## Planned Features

- **Export** -- Export case type definitions as portable JSON/YAML packages.
- **Import** -- Import case type definitions from other Dossiq instances.
- **Version management** -- Track versions of case type definitions.
- **Marketplace** -- Share case type definitions through a central catalog (e.g., via OpenCatalogi).
- **Dependency resolution** -- Handle dependencies between case types (e.g., sub-case types, related schemas).
- **Migration** -- Support upgrading case type definitions while preserving existing case data.
- **Validation** -- Validate imported definitions before applying them.

## Use Cases

- A municipality develops a specialized WOO case type and shares it with other municipalities.
- A software vendor provides pre-built case type packages for common government processes.
- An organization migrates case type definitions from a test environment to production.

## Status

This feature is defined in the spec at `openspec/specs/case-definition-portability/spec.md` and is planned for future implementation.
