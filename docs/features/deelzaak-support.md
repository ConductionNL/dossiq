# Deelzaak Support (Sub-case Hierarchy)

**Issue:** #83
**Branch:** `feature/83/deelzaak-support`

## Overview

Adds hierarchical case support (deelzaken) enabling a parent case to spawn child cases for parallel departmental processing. For example, an Omgevingsvergunning parent case can spawn separate sub-cases for building assessment, environmental impact, and fire safety — each with their own lifecycle, assignees, and deadlines.

## Capabilities

- Create sub-case from parent case detail view with case type constraint enforcement (`subCaseTypes` list)
- Sub-cases section on parent case detail showing title, status, assignee, and deadline
- Sub-case progress roll-up indicator ("X/Y completed") in section header
- Parent case breadcrumb navigation on sub-case detail views
- Sub-case count badge in case list for parent cases
- Nesting prevention: sub-cases cannot spawn further sub-cases (single level only)
- Deletion protection: warns about sub-case detachment, clears `parentCase` on children before deleting parent
- Case store extended to support filtering by `parentCase` and loading sub-case trees

## ZGW Mapping

Maps bidirectionally to ZGW `hoofdzaak` / `deelzaken` fields in the Zaken API (ZRC-013 compliant).

## Validation

- Sub-case cannot be created if the parent case is closed
- Sub-case type must be in the parent case type's `subCaseTypes` list
- Maximum nesting depth: 1 level (sub-cases cannot have sub-cases)
