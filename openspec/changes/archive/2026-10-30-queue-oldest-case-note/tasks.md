# Tasks: queue-oldest-case-note

### Task 1: The waiting-longest tile names the oldest case's type and deadline
- **spec_ref**: `openspec/changes/queue-oldest-case-note/specs/werkvoorraad-intelligent-queue/spec.md#requirement-the-waiting-longest-tile-names-the-oldest-cases-type-and-deadline`
- **files**: `lib/Service/Queue/QueueFigures.php`, `lib/Service/KpiAggregationService.php`, `src/manifest.json`, `l10n/*`
- [x] Implement: oldest type and due days, the note, the caption in the error tone
- [x] Test: `tests/Unit/Service/Queue/QueueFiguresTest.php`, `tests/Unit/Service/KpiAggregationServiceTest.php`
