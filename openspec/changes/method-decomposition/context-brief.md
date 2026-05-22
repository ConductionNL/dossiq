## Why

Method Decomposition -- Procest is not yet implemented in Procest. This change proposes adding this capability based on the detailed spec in `specs/method-decomposition/spec.md`.

**Feature tier**: V1 (Priority 1 files), V2 (Priority 2+3 files)

## What Changes

See `specs/method-decomposition/spec.md` for full requirements and scenarios.

## Impact

- **Code**: New frontend views and/or backend services
- **Dependencies**: OpenRegister (data storage), Nextcloud platform APIs
- **Testing**: Unit tests for new services, integration tests for API endpoints

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › Workflow-editor

**Rationale:** Methode-decompositie onder workflow.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.
