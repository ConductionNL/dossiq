# Complaints are a case type

## Why

Row 2.53 (register and handle complaints under Awb chapter 9 with deadlines, hearing and disposition). The backend exists as procedure code: `lib/Service/ComplaintService.php`, the `complaint` schema in `lib/Settings/dossiq_register.json` and the routes `/api/complaints`, `/api/complaints/deadline-alerts`, `/analytics` and `/kpi` in `appinfo/routes.php`. Nothing a user opens reaches them: no page, menu entry or action on development `a5c9b4153`. Decision 182 says a procedure is a case type, flows and configuration, and decision 184 moves procedure-named classes onto generic capabilities. So the build is not a complaint page: it is a complaint case type that runs on what every case already has.

## What changes

- The register's case type `klacht-behandeling` (Awb 9:1), already shipped on every instance, is completed: statuses as the steps of board DqKlachten (Ontvangen, Bevestigd, Hoorgesprek, Oordeel, Antwoordbrief, Afgehandeld), roles (Klager, Betrokken medewerker, Klachtencoördinator), result types (gegrond, deels gegrond, ongegrond, niet-ontvankelijk, ingetrokken) and the term of Awb 9:11 (6 weeks from receipt, one extension of at most 4 weeks; 10 weeks with a complaints committee is open, see tasks 1.3). It is not a package: a package would adopt the existing case type and could not add the case type's own declarations to it (decision 190).
- The hearing (Awb 9:10) is the generic session with parties and minutes (decision 191), declared on the case type, not complaint code.
- Board DqKlachten's list is the Complaints view of the Cases list; its KPIs are the generic dashboard widgets on that view.
- Existing `complaint` objects are carried into cases on upgrade by the generic record import (`recordImports`), then `ComplaintService`, the five complaint controllers and their routes retire after one release.

## Impact

- `lib/Settings/dossiq_register.json`: the `klacht-behandeling` case type (sessions, record import), its statuses, roles and result types; register version bumped by one.
- Removal of `ComplaintService`, the five `Complaint*Controller` classes and their routes one release later.
- Spec: requirements added to `complaint-management`.
