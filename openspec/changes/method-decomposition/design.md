---
status: pr-created
kind: code
issue: 463
change_name: method-decomposition
---

# Design: Method Decomposition — Procest

## What & Why

Eliminate 152 PHPMD complexity suppressions by decomposing complex methods into focused handler/service classes. Pure refactoring — no behavioral changes, no API surface changes.

## Approach

### Handler pattern (controllers)
Extract complex private methods from controllers into dedicated handler classes under `lib/Controller/{ControllerName}/`. Each handler is injected via the controller constructor and delegates receive the minimum dependencies they need.

### Service extraction pattern
Extract coherent groups of methods from large services into focused services (`JwtValidationService`, `ZgwSubResourceResolver`, `StatusTransitionValidator`, `ZaaktypeValidator`, `ZgwReferenceResolver`, `FieldValidator`). Injected into the original service to keep the public interface stable.

### Decorator continuation
The original public APIs remain unchanged. All new classes are internal implementation details.

## Declarative-vs-imperative decision

No schema register changes needed — this is pure code structure refactoring with no domain logic changes.

## MCP coverage

No MCP surface — pure refactoring, no new user-callable actions.

## New classes

### V1 (Priority 1)

**lib/Controller/ZrcController/**
- `ZaakAuthorizationHandler.php` — Authorization filtering for zaken (zrc-006a/b)
- `ZaakValidationHandler.php` — Pre-validation, producten check (zrc-010/015)
- `ZaakDeleteHandler.php` — Cascade delete zaak (zrc-023)
- `EindstatusHandler.php` — Eindstatus effects, gebruiksrecht check, archiefparameters (zrc-007/021)

**lib/Service/**
- `JwtValidationService.php` — JWT structure/expiry/signature/claims validation
- `ZgwSubResourceResolver.php` — Sub-resource lookups (resolveZaakClosed, resolveParentZaaktypeDraft)
- `StatusTransitionValidator.php` — Status transition + zaak status update rules
- `ZaaktypeValidator.php` — Zaaktype create/sub-type validation
- `ZgwReferenceResolver.php` — URL reference resolution (shared across rules services)
- `FieldValidator.php` — Date + URL field validation utilities

**lib/Controller/ZtcController/**
- `InformatieObjectTypeHandler.php` — IOT create handler
- `StatusTypeHandler.php` — StatusType create handler
- `ResultaatTypeHandler.php` — ResultaatType create handler

**lib/Controller/BrcController/**
- `BesluitHandler.php` — Besluit create handler

**lib/Controller/DrcController/**
- `DocumentHandler.php` — Document create handler

### V2 (Priority 2+3)

**lib/Controller/AcController/**
- `AutorisatieHandler.php` — Autorisatie create handler

**lib/Service/**
- `MappingTransformService.php` — Mapping transformation (from ZgwMappingService)

## Testing

New test files per Rule 0c:
- `tests/Unit/Controller/ZrcController/ZaakAuthorizationHandlerTest.php`
- `tests/Unit/Controller/ZrcController/ZaakValidationHandlerTest.php`
- `tests/Unit/Controller/ZrcController/ZaakDeleteHandlerTest.php`
- `tests/Unit/Controller/ZrcController/EindstatusHandlerTest.php`
- `tests/Unit/Service/JwtValidationServiceTest.php`
- `tests/Unit/Service/ZgwSubResourceResolverTest.php`
- `tests/Unit/Service/StatusTransitionValidatorTest.php`
- `tests/Unit/Service/ZaaktypeValidatorTest.php`
- `tests/Unit/Service/ZgwReferenceResolverTest.php`
- `tests/Unit/Service/FieldValidatorTest.php`
- `tests/Unit/Controller/ZtcController/InformatieObjectTypeHandlerTest.php`
- `tests/Unit/Controller/ZtcController/StatusTypeHandlerTest.php`
- `tests/Unit/Controller/ZtcController/ResultaatTypeHandlerTest.php`
- `tests/Unit/Controller/BrcController/BesluitHandlerTest.php`
- `tests/Unit/Controller/DrcController/DocumentHandlerTest.php`
