# RBAC per Zaaktype

> **Note:** Relocated from `openregister/openspec/changes/rbac-zaaktype/` on 2026-04-30. The original framing called this an "abstract extension of OpenRegister's RBAC system" but the requirements use ZGW vocabulary throughout (zaaktype, vertrouwelijkheidaanduiding, Zaakcatalogus, ZGW Autorisaties API, VNG compliance). All zaaktype code already lives in Procest (`AcController`, `ZtcController`, `ZgwBusinessRulesService`, `ZgwMappingService`, `ZgwZrcRulesService`); zero zaaktype code in OpenRegister. The spec is implementation work for ZGW Autorisaties, not a generic RBAC pattern, so it belongs adjacent to the existing `zaaktype-configuratie` change in Procest. OpenRegister provides the underlying RBAC primitives (PermissionHandler, MagicRbacHandler, ConditionMatcher) this spec composes against.

## Problem
Define zaaktype-scoped authorization layered on top of OpenRegister's existing RBAC primitives. This spec does NOT introduce a new authorization engine — it defines how the existing PermissionHandler and MagicRbacHandler conditional rules can be configured to enforce zaaktype-level access control, as required by the ZGW Autorisaties API.

## Proposed Solution
Define zaaktype-scoped authorization as an abstract extension of OpenRegister's existing RBAC system. This spec does NOT introduce a new authorization engine — it defines how the existing PermissionHandler and MagicRbacHandler conditional rules can be configured to enforce zaaktype-level access control, as required by the ZGW Autorisaties API. The core RBAC infrastructure (schema-level permissions, property-level filtering, database-level SQL conditions, admin bypass, conditional matching with o
