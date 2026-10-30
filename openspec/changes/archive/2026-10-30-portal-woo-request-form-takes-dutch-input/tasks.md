# Tasks: portal-woo-request-form-takes-dutch-input

### Task 1: The route reads a day written day first and the requester's word
- **spec_ref**: `openspec/changes/portal-woo-request-form-takes-dutch-input/specs/woo-request-intake/spec.md#requirement-the-woo-request-reads-the-answers-a-resident-gives-req-wdi-001`
- **files**: `lib/Woo/WooRequestForm.php`, `tests/Unit/Woo/PortalWooRequestDutchAnswersTest.php`
- [x] Implement
- [x] Test (through `PortalWooRequestController::start()` with the real intake)

### Task 2: A refusal names its field in Dutch
- **spec_ref**: `openspec/changes/portal-woo-request-form-takes-dutch-input/specs/woo-request-intake/spec.md#requirement-a-refused-woo-request-names-the-field-in-the-residents-language-req-wdi-002`
- **files**: `lib/Woo/WooRequestRefused.php`, `lib/Woo/WooRequestForm.php`, `lib/Controller/PortalWooRequestController.php`, `l10n/*`
- [x] Implement
- [x] Test (through the route, worded from the shipped catalogue)

### Task 3: The confirmation names its dates as days
- **spec_ref**: `openspec/changes/portal-woo-request-form-takes-dutch-input/specs/woo-request-intake/spec.md#requirement-the-confirmation-names-its-dates-as-days-req-wdi-003`
- **files**: `lib/Service/Support/ReadableDay.php`, `lib/Service/Termijn/TermLetters.php`, `lib/Woo/WooWrittenCase.php`, `lib/Portal/CitizenManifest.php`
- [x] Implement
- [x] Test (`TermLettersTest` stays green on the moved formatter)

### Task 4: Dates asked as dates
- **spec_ref**: `openspec/changes/portal-woo-request-form-takes-dutch-input/specs/portal-contribution/spec.md#requirement-the-woo-request-form-asks-dates-as-dates-and-the-requester-as-a-choice-req-wdi-004`
- **files**: `lib/Portal/CitizenManifest.php`, `tests/Unit/Portal/PortalContributionProviderTest.php`
- [x] Implement
- [x] Test
