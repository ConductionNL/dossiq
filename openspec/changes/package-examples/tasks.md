# Tasks

- [x] 1.1 `examples` part: package rules accept it, the installer writes it after the seed rows
- [x] 1.2 Relative date tokens in example rows (PackageExampleDates)
- [x] 1.3 Example cases: bezwaar-beroep 1.1.0 (objection with decision, chair, objection, hearing, advice, term), jeugdwet (report with consent), aanbesteding (tender in evaluation)
- [x] 1.4 Unit test: no lookup of the Zuiddrecht set needs an on-request package
- [x] 1.5 Example rows install only when the setup wizard's example data decision is `installed` (DemoDataService::DECIDED_KEY); otherwise skipped with one log line
- [ ] 1.6 Follow-up: when an admin loads example data after a package was installed, install that package's examples then (the package version is already recorded, so a reinstall reports it current)
- [x] 1.7 Bids on the example tender: aanbesteding 1.1.0 ships four suppliers and their bids, written to dossiq's own supplier schemas by reference
