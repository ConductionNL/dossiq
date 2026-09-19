# Tasks: intake-says-when-the-term-starts

Tier: V1. Kind: code. Row Q8.21. Reads openregister
`working-calendar-admin` (to be specified) through dossiq
`terms-on-the-engine-calendar`, which SHIPPED as dossiq#2941 while this
change was being built, so the engine calendar answers rather than
`WorkingDayCalculator`.

- [x] 1.1 `lib/Settings/dossiq_register.json`: `case.receivedAt`,
  `case.termStartsAt`, `case.receivedOutsideWorkingHours` (D-1, D-2).
- [x] 1.2 Populate all three at case creation from the engine calendar,
  through one call (D-3).
  - `tests/Unit/Service/IntakeTermStartTest.php`
  - `@spec openspec/changes/intake-says-when-the-term-starts/specs/burger-notifications/spec.md`
  - ONE CALENDAR, AND NOT A SECOND IMPLEMENTATION OF THE FIRST.
    `WorkingDayRoll` already asks the engine for `add(0 business days)`,
    which answers the instant given on a working day and the start of the
    next working day otherwise. That IS "when does the clock start", so this
    change adds `firstWorkingMomentAtOrAfter()` beside `roll()` on that class
    rather than a second resolver of the same seam. D-3 asked for exactly
    that: a second source would eventually disagree, and the disagreement
    would surface as a citizen quoting a date the system does not recognise.
  - AN UNREACHABLE CALENDAR STAMPS NOTHING, which is the decision worth
    reading twice. It would have been easy to write `receivedAt` and leave
    the flag false. That puts a claim on the record nobody computed, and the
    ontvangstbevestiging would then quote a start the term does not use. An
    absent stamp is visibly absent.
  - Written once, by `lib/Listener/IntakeTermStartListener.php`, and never
    again: a case that already carries `termStartsAt` is skipped, so a
    replayed create event cannot re-stamp it against a calendar that has
    gained a holiday since. That is the whole of "SHALL NOT be recomputed on
    read".
- [ ] 2.1 [partial] The on-screen intake confirmation names the reference,
  the received moment, the start and the deadline (D-5).
  - WHAT SHIPPED: the three fields are on
    `PortalContributionProvider::CITIZEN_CASE_FIELDS`, which is the ONE list
    the portal projects a case down to and the ontvangstbevestiging quotes
    back. Adding them there is what puts the answer on both surfaces from one
    definition, and `IntakeConfirmationTextTest::testTheCitizenMaySeeTheThreeFields`
    is what says so. They also render on the case page's Receipt confirmation
    section (`src/manifest.json`, read-only), which is where a handler
    answering a complaint in March reads what the citizen was told in
    January.
  - WHAT DID NOT: the citizen's own screen immediately after pressing send is
    portaliq's surface, not dossiq's, so the moment-after-submitting
    rendering is not in this change. dossiq now supplies everything that
    screen needs; portaliq drawing it is the other half.
  - `tests/vitest/intakeConfirmation.spec.js` is NOT written, and this line
    is the reason rather than an omission: the surface this change adds is a
    declarative `data` widget over three schema fields, so there is no
    component to mount. The manifest side is asserted from PHP, over the same
    file, in the test named above.
- [x] 2.2 The ontvangstbevestiging names the same four, with the extra
  sentence only when the two moments differ (D-4).
  - `lib/Service/TermijnNotificationService.php` (`termStartLines()`),
    `lib/Service/AcknowledgementService.php` (the render context).
  - `lib/Service/EmailTemplateService.php` is NOT touched: it holds the
    template's slug and metadata, and the body is built in
    `TermijnNotificationService::renderTemplate()`. Editing the first would
    have changed nothing a citizen reads.
  - The lines are dropped entirely on an unstamped case, rather than falling
    back to today. A start nobody computed is worse than no start at all.
- [x] 2.3 Dutch and English strings for both surfaces (ADR-025).
  - Both bodies are written out in full per language in
    `TermijnNotificationService`, and deliberately NOT run through `IL10N`:
    that serves the interface language of the signed-in reader, and the
    reader here is a citizen with no Nextcloud session. The class says so
    already; this change follows it rather than introducing a second habit.
    `IntakeConfirmationTextTest` asserts both, because two bodies written out
    separately is exactly how a line lands in one and not the other.
  - The case-page field labels come from the schema titles, which ship in the
    register descriptor, so `node tests/l10n/check-l10n.js` stays green with
    no new catalogue keys.
- [x] 3.1 `tests/e2e/intake-says-when-the-term-starts.spec.ts`, filing
  inside and outside the working window; `openspec validate
  intake-says-when-the-term-starts --strict`.
  - Written, tagged and NOT RUN: the integration branch defers Playwright to
    the nightly.
  - TWO SCENARIOS LOST THEIR `@e2e` TAG AND GAINED A REASON. The
    ontvangstbevestiging is sent by `AcknowledgementDispatchJob`, a
    background job with no HTTP trigger, so there is nothing for a browser to
    press. Both mail scenarios now carry `@e2e exclude` naming
    `IntakeConfirmationTextTest` and the test method, because "covered
    elsewhere" without an address is how seven requirements in this
    repository went untested for eight months.

## The gap this change does not close, stated rather than discovered

The stamp answers at DAY granularity, because that is all the engine calendar
holds: `WorkingCalendar` carries working weekdays, non-working dates and hours
per working day, and no intra-day window. So a request filed at 23:00 on a
Tuesday reads as inside the working week and its clock starts at 23:00. The
window is openregister `working-calendar-admin`, still to be specified, and
this change is written to need no edit when it lands: the flag compares
INSTANTS rather than dates, and
`IntakeTermStartTest::testAnEarlyMorningFilingOnTheSameDayIsStillOutside`
already fails if somebody simplifies that comparison.
