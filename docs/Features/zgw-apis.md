# ZGW API Compliance

Procest implements the Dutch ZGW (Zaakgericht Werken) API suite, enabling interoperability with other ZGW-compliant systems (OpenZaak, Dimpact ZAC, XXLLnc Zaken) and passing the VNG Newman test suite.

## ZGW APIs Implemented

| API | Version | Description | Status |
|-----|---------|-------------|--------|
| Zaken API (ZRC) | 1.x | Case lifecycle management | Implemented |
| Catalogi API (ZTC) | 1.x | Case type catalogue | Implemented |
| Documenten API (DRC) | 1.x | Document management with binary upload | Implemented |
| Besluiten API (BRC) | 1.x | Decision registration | Implemented |
| Autorisaties API (AC) | 1.x | API client and JWT authorization | Implemented |
| Notificaties API (NRC) | 1.x | Webhook-based event notifications | Implemented |

## Autorisaties API (AC)

Manages API client (applicatie) registration and authorization:
- JWT token generation and validation
- Scope-based access control per ZGW API
- Maps to OpenRegister's Consumer entity and AuthorizationService
- Required by VNG Newman OAS tests for all ZGW API test runs

## Documenten API (DRC)

Full document management with binary file I/O:
- `enkelvoudiginformatieobject`: document metadata + file content
- Binary upload via `inhoud` field (base64 or multipart)
- File content stored in Nextcloud filesystem
- Linked to cases via `zaakinformatieobject` resources
- `indicatieGebruiksrecht` lifecycle enforcement on zaak close

## Notificaties API (NRC)

Webhook-based event publishing for ZGW resource changes:
- Channel subscriptions: `zaken`, `documenten`, `besluiten`, `catalogi`
- Abonnement (subscription) registration and management
- Event dispatch on create/update/delete of ZGW objects
- n8n workflow automation via webhook triggers

## Business Rules Compliance

VNG ZGW business rules compliance (tracked against the 353-assertion Newman test suite):

| Rule | Description | Status |
|------|-------------|--------|
| ZRC-007a | Set `einddatum` on eindstatus creation | Fixed |
| ZRC-007b | Set `indicatieGebruiksrecht` on all docs when zaak closes | Fixed |
| ZRC-007q | Validate all docs have `indicatieGebruiksrecht` before eindstatus | Fixed |
| ZRC-008c | Check `zaken.heropenen` scope before reopening closed zaak | Fixed |
| ZRC-010 | `communicatiekanaal` validation error codes | Fixed |
| ZRC-013a | `hoofdzaak` not-found error code | Fixed |
| ZRC-015 | Validate `productenOfDiensten` subset | Fixed |

## Newman Test Suite

Automated ZGW compliance testing via VNG Postman collections:
- **ZGW OAS tests**: validates all 6 ZGW APIs against their OpenAPI specs
- **ZGW business rules**: validates business logic, edge cases, and authorization
- Newman runs locally and in CI via `make test:zgw`
- Test environment configuration in `data/newman-environment.json`

## Standards References

- [ZGW API standaard: zaakgrichtwerken.nl](https://zaakgerichtwerken.nl)
- [VNG Realisatie ZGW GitHub](https://github.com/VNG-Realisatie/gemma-zaken)
- [ZRC 1.x OAS](https://vng-realisatie.github.io/gemma-zaken/standaard/zaken/index)
- [DRC 1.x OAS](https://vng-realisatie.github.io/gemma-zaken/standaard/documenten/index)
