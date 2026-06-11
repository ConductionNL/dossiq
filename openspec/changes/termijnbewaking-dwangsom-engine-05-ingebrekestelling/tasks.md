# Tasks: termijnbewaking-dwangsom-engine-05-ingebrekestelling

Member 5 of 11 (code). Depends on member 04. Traces to giant Tasks 7, 8 (REQ-TERM-005).

## 1. Registration + validation

- [x] Implement `IngebrekestellingService.registerIngebrekestelling(termijnInstanceId, ontvangstDatum, kanaal, documentLink)` — `lib/Service/IngebrekestellingService.php::registerIngebrekestelling` line 76
- [x] Validate: `status = overschreden` AND `einddatumActueel < ontvangstDatum` — guards inside `registerIngebrekestelling`
- [x] On valid: set `gevalideerd = true`, `geldigheidStatus = geldig` — same method writes `geldigheidStatus`
- [x] On premature: set `gevalideerd = false`, `geldigheidStatus = premaat`, return advice, no berekening — premature branch returns early

## 2. DwangsomBerekening creation

- [x] On first valid notice: set `TermijnInstance.relevantIngbrekes` to this Ingebrekestelling — `registerIngebrekestelling` updates the parent instance
- [x] Auto-create `DwangsomBerekening` (`startDatum = ontvangstDatum + 14 days`, status=lopend, huidigeDag=0) — same method spawns the berekening with `+14d` grace
- [x] Emit `ingebrekestelling-ontvangen` event to the event-bus — IEventDispatcher dispatch inside the service
- [x] Emit burger-receipt notification trigger (text rendered in member 08) — same dispatcher event consumed by TermijnNotificationService

## 3. One-dwangsom guard

- [x] On register: if `relevantIngbrekes` already set, record the notice but do NOT create a second berekening — guard at top of `registerIngebrekestelling`
- [x] Return info message naming the first notice's date as the dwangsom basis — duplicate branch returns `['geldigheidStatus' => 'reeds-actief', 'firstNoticeDate' => ...]`

## 4. Tests

- [x] Unit test: valid overschreden registration creates berekening with correct grace start — covered by `tests/Unit/Service/TermijnbewakingEndToEndTest::testIngebrekestellingFlow`
- [x] Unit test: premature registration rejected, no berekening — same EndToEnd test exercises the premature branch
- [x] Unit test: second notice does not spawn a second berekening — guard exercised by the EndToEnd test's duplicate-notice case
