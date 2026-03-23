# Design: prometheus-metrics

## Architecture

No new classes needed. Enhancements to existing `MetricsController` and `HealthController`.

## Changes

### MetricsController

1. **Inject `IAppManager`** (already injected) to get Nextcloud version via `\OC_Util::getVersionString()` or `\OCP\ServerVersion` if available
2. **Info gauge**: Add `nextcloud_version` label
3. **New metric**: `procest_cases_created_today` — query cases with `startDate` matching today
4. **APCu caching**: Wrap expensive queries (`getCaseCounts`, `getOverdueCasesCount`, `getTaskCounts`, `getOverdueTasksCount`) in APCu cache with 30-second TTL

### HealthController

1. **Inject `IAppManager`** (already injected)
2. **Add OpenRegister check**: Verify `openregister` app is enabled via `$appManager->isEnabledForUser()`
3. **Status logic**: OpenRegister unavailable = "error" status (hard dependency)

### Caching Helper

Add private `getCached(string $key, int $ttl, callable $compute)` method to MetricsController that:
- Checks APCu for cached value
- On miss, calls the compute callable and stores result
- Gracefully falls back to direct computation if APCu is unavailable

## File Changes

| File | Change |
|------|--------|
| `lib/Controller/MetricsController.php` | Add NC version to info gauge, add cases_created_today, add APCu caching |
| `lib/Controller/HealthController.php` | Add OpenRegister dependency check |
| `tests/Unit/Controller/MetricsControllerTest.php` | New test file |
| `tests/Unit/Controller/HealthControllerTest.php` | New test file |
