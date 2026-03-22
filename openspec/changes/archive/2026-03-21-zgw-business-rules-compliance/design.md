# Design: ZGW Business Rules Compliance

## Architecture
- **Backend**: Rule services per ZGW API component
- **Services**: `ZgwZrcRulesService`, `ZgwZtcRulesService`, `ZgwDrcRulesService`, `ZgwBrcRulesService`
- **Base**: `ZgwRulesBase` provides shared validation utilities
- **Orchestration**: `ZgwBusinessRulesService` coordinates cross-component rules
- **Performance**: OpenRegister property inversion and optimized search for enrichment

## Key Files
| File | Purpose |
|------|---------|
| `lib/Service/ZgwZrcRulesService.php` | ZRC (Zaken) business rules (zrc-001 through zrc-023) |
| `lib/Service/ZgwZtcRulesService.php` | ZTC (Catalogi) business rules |
| `lib/Service/ZgwDrcRulesService.php` | DRC (Documenten) business rules |
| `lib/Service/ZgwBrcRulesService.php` | BRC (Besluiten) business rules |
| `lib/Service/ZgwRulesBase.php` | Shared validation utilities |
| `lib/Service/ZgwBusinessRulesService.php` | Cross-component orchestration |
| `lib/Service/ZgwService.php` | Core ZGW data operations |
| `lib/Controller/ZrcController.php` | Zaken API controller |
| `lib/Controller/ZtcController.php` | Catalogi API controller |
| `lib/Controller/DrcController.php` | Documenten API controller |
| `lib/Controller/BrcController.php` | Besluiten API controller |

## Testing
- Newman test suite: `tests/zgw/` with 353 assertions
- Target: 0 failures, average response time under 200ms
