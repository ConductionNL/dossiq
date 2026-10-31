# first-time-setup Delta: example-data-fills-the-work-pages

**Status**: draft

## ADDED Requirements

### Requirement: Example data fills the work pages

The example data the setup wizard loads SHALL include curated, fictional work for the user who loads it: assigned cases with deadlines relative to the day of loading, a Woo request with assessed documents and refusal grounds, and data subject requests. Load-time tokens (`@me`, `@myGroup`, `@today±Nd`, `@now±Nd`) SHALL be filled in before the import. `@myGroup` is the loader's first group other than `admin`, by id. With nobody signed in, or with no such group, the property SHALL be left out.

#### Scenario: Loading the example data assigns work to the loader
@e2e exclude loading writes example data to the shared instance; covered by `tests/Unit/Service/Setup/ExampleDataFragmentsTest.php` and `tests/Unit/Service/DemoDataServiceTest.php::testTheCuratedFragmentsAreImportedWithTheGeneratedSet`

- **GIVEN** a fresh install
- **WHEN** pieter loads the example data on 19 October
- **THEN** four Woo requests are assigned to pieter, one with a deadline of 21 October
- **AND** the Marktplein request holds two partly public documents with refusal grounds
