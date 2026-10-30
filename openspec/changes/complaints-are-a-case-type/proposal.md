# Complaints are a case type

## Why

Row 2.53 (register and handle complaints under Awb chapter 9 with deadlines, hearing and disposition). The backend exists as procedure code: `lib/Service/ComplaintService.php`, the `complaint` schema in `lib/Settings/dossiq_register.json` and the routes `/api/complaints`, `/api/complaints/deadline-alerts`, `/analytics` and `/kpi` in `appinfo/routes.php`. Nothing a user opens reaches them: no page, menu entry or action on development `a5c9b4153`. Decision 182 says a procedure is a case type, flows and configuration, and decision 184 moves procedure-named classes onto generic capabilities. So the build is not a complaint page: it is a complaint case type that runs on what every case already has.

## What changes

- A package `klachtbehandeling` (`lib/Settings/packages/klachtbehandeling/`) with the case type "Klacht" (Awb 9:1), its statuses (ontvangen, in behandeling, hoorzitting, afgehandeld), roles (klager, klachtbehandelaar, betrokken medewerker), result types (gegrond, ongegrond, deels gegrond, ingetrokken, niet ontvankelijk) and the term: 6 weeks from receipt, 10 weeks with a complaints committee, one 4-week extension (Awb 9:11). A lane verifies the article text and cites it in the package description.
- The hearing (Awb 9:10) is the generic session with parties and minutes (decision 191), not complaint code.
- Board DqKlachten's list is a saved view of cases filtered on this case type; its KPIs are the generic dashboard widgets on that view.
- Existing `complaint` objects are carried into cases once, then `ComplaintService`, the five complaint controllers and their routes retire after one release.

## Impact

- New package directory with `package.json`, `case-type.json`, `flows/klacht.json`, `examples/`.
- Repair step that carries `complaint` objects into cases; removal of `ComplaintService`, the five `Complaint*Controller` classes and their routes one release later.
- Spec: requirements added to `complaint-management`.
