# Tasks: slug-search-takes-the-openregister-target

- [x] 1.1 The bridge passes the target in the shape the service's slug search takes (`lib/Service/Support/SearchesObjects.php`; test `tests/Unit/Service/Support/SlugSearchTargetTest.php`).
- [x] 1.2 Live: on NC 34.0.6 with OpenRegister main, `GET /api/case-types/{id}/terms` and `GET /api/cases/{id}/aanvullingsverzoeken` answer 200 instead of 503.
