# Tasks: example-data-fills-the-work-pages

- [x] 1. `ExampleDataFragments` reads `lib/Settings/demo.d` and fills `@me`, `@today±Nd`, `@now±Nd`
  - Test: tests/Unit/Service/Setup/ExampleDataFragmentsTest.php (tokens, no user, every object fits its schema)
- [x] 2. `DemoDataService` imports the fragments with the generated set and counts them in the offer
  - Test: tests/Unit/Service/DemoDataServiceTest.php::testTheCuratedFragmentsAreImportedWithTheGeneratedSet
- [x] 3. `lib/Settings/demo.d/10-work-pages.json`: Woo requests, assessed documents, AVG requests
