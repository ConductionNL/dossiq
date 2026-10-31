# Tasks: background-reads-run-as-the-system

- [x] 1.1 `asSystemWithoutUser()` on the shared object-search trait.
- [x] 1.2 The two case-type reads use it.
  - `tests/Unit/Service/Support/BackgroundReadsRunAsSystemTest.php` (controls: no session is refused, a signed-in caller is not elevated)
- [x] 1.3 The timeline write uses it.
  - `tests/Unit/Service/Timeline/CaseTimelineTest.php`
