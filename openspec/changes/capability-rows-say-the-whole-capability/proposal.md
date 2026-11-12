# Capability rows say the whole capability

## Why

Forty-five rows in the dossiq matrix link requirements that cover only part of the capability.
The part no scenario says can be neither built nor checked. This change writes that part down, one requirement per row.

### Matrix rows (dossiq `openspec/parity/capabilities.json`)

| row | requirement | verdict |
|---|---|---|
| `1.4` | A document in the intake queue can be rejected with a reason (REQ-CRS-01) in `intake-from-a-channel` | provider |
| `2.13` | none, the main spec contradicts it | contradicted |
| `3.9` | A transition can generate a document from a template and open a related case (REQ-CRS-03) in `automatic-actions` | built |
| `5.4` | The contact page lists the contact's documents and communication (REQ-CRS-04) in `party-pages` | built |
| `5.11` | A person or company is taken over from its registry and keeps following it (REQ-CRS-05) in `brp-register` | built |
| `7.4` | A decision letter can be generated as a step of the case's flow (REQ-CRS-06) in `vth-beschikking-generation` | built |
| `8.7` | none, the main spec contradicts it | contradicted |
| `9.3` | A handler saves a search as a view of their own (REQ-CRS-08) in `case-search-and-lists` | built |
| `10.5` | An administrator exports the filtered audit log (REQ-CRS-09) in `admin-settings` | provider |
| `11.5` | A BPMN process file can be imported as a flow (REQ-CRS-10) in `workflow-import-export` | provider |
| `11.11` | An administrator lists, adds and edits document templates (REQ-CRS-11) in `generate-fills-per-template` | provider |
| `11.17` | An administrator creates and edits an object type (REQ-CRS-12) in `admin-settings` | provider |
| `11.24` | An administrator edits the text of each notification (REQ-CRS-13) in `admin-settings` | not-built |
| `13.1` | Each application role states what its members may do (REQ-CRS-14) in `case-access-control` | not-built |
| `13.16` | An administrator edits the role matrix as a grid (REQ-CRS-15) in `role-routing-via-or-rbac` | provider |
| `3.11` | A decision table's answer is written to the case (REQ-CRS-16) in `dmn-decision-tables` | built |
| `3.18` | The case page shows its task panel beside the tabs (REQ-CRS-17) in `case-page` | not-built |
| `6.5` | The organisation's mailbox lists every unfiled message and files one on a case (REQ-CRS-18) in `intake-from-a-channel` | built |
| `8.1` | A lead time in days yields the case's target date (REQ-CRS-19) in `termijn-binding` | built |
| `8.12` | An administrator maintains the working calendar in one place (REQ-CRS-20) in `termijnbewaking-schemas` | provider |
| `9.9` | A handler searches persons by BSN (REQ-CRS-21) in `initiator-selection` | built |
| `11.19` | An administrator manages departments and assigns users to roles in the app (REQ-CRS-22) in `admin-settings` | built |
| `12.6` | A person or company is looked up in the live registry when one is connected (REQ-CRS-23) in `brp-register` | provider |
| `12.23` | The assistant carries out a case action after the handler confirms it (REQ-CRS-24) in `ai-assistance` | not-built |
| `1.9` | A case can be started from a Word or Excel document (REQ-CRS-25) in `intake-from-a-channel` | provider |
| `2.15` | A handler links an object to a case from its page (REQ-CRS-26) in `case-management` | built |
| `2.22` | An admin reads a case's flow variables and run log (REQ-CRS-27) in `case-management` | provider |
| `3.1` | none, the main spec contradicts it | contradicted |
| `3.4` | A person fills in and submits a task's form, and an invalid answer is refused (REQ-CRS-29) in `case-flow-human-steps` | built |
| `3.12` | A multi-step form shows or skips a step by a condition (REQ-CRS-30) in `friendly-case-create-form` | provider |
| `4.12` | The signed document comes back onto the case (REQ-CRS-31) in `libresign-besluit-signing` | built |
| `5.10` | A handler looks up an address object and links it to the case (REQ-CRS-32) in `case-management` | built |
| `6.3` | A handler attaches case documents to a mail from the case (REQ-CRS-33) in `case-email-integration` | not-built |
| `8.2` | An overdue case is marked on its page and in the case list (REQ-CRS-34) in `my-work` | built |
| `10.1` | A dashboard can show a KPI as a gauge (REQ-CRS-35) in `dashboard` | not-built |
| `10.4` | Process analytics shows dwell time as a heatmap (REQ-CRS-36) in `process-mining-bottlenecks` | not-built |
| `10.7` | The exports area shows how often each export was downloaded (REQ-CRS-37) in `case-list-export-via-or-export-leaf` | provider |
| `11.20` | An administrator writes a rule's conditions and actions (REQ-CRS-38) in `status-transition-engine` | built |
| `11.23` | You group templates in folders (REQ-CRS-39) in `property-definition-management` | not-built |
| `12.8` | A resident from another EU country logs in with eIDAS (REQ-CRS-40) in `zaakportaal-mijngemeente` | provider |
| `12.16` | The map can show layers from an ArcGIS service (REQ-CRS-41) in `gis-integration` | not-built |
| `12.28` | A transfer hands the case over, and the sender follows its activity (REQ-CRS-42) in `federated-case-collaboration` | built |
| `13.3` | A handler grants and withdraws access to one case from its page (REQ-CRS-43) in `case-access-control` | built |
| `13.10` | An approved destruction list destroys its cases (REQ-CRS-44) in `case-management` | provider |
| `16.2` | An inspector carries out an inspection without a connection (REQ-CRS-45) in `inspection-checklists` | not-built |

## What changes

- One ADDED requirement per row, in the main spec the row already links, or the nearest dossiq spec when the row links only another repo.
- Each requirement says the missing part as behaviour a reader can see, with a scenario.
- Rows `2.13`, `8.7` and `3.1` get no requirement. Their main spec refuses what the row claims, so Ruben decides first.

## Impact

- 18 rows are already built; their tasks are ticked with the file that does it.
- 10 rows need work in dossiq and 14 rows wait on another repo (filinq, openregister, integriq, buildiq).
- Specs touched: `intake-from-a-channel`, `automatic-actions`, `party-pages`, `brp-register`, `vth-beschikking-generation`, `case-search-and-lists`, `admin-settings`, `workflow-import-export`, `generate-fills-per-template`, `case-access-control`, `role-routing-via-or-rbac`, `dmn-decision-tables`, `case-page`, `termijn-binding`, `termijnbewaking-schemas`, `initiator-selection`, `ai-assistance`, `case-management`, `case-flow-human-steps`, `friendly-case-create-form`, `libresign-besluit-signing`, `case-email-integration`, `my-work`, `dashboard`, `process-mining-bottlenecks`, `case-list-export-via-or-export-leaf`, `status-transition-engine`, `property-definition-management`, `zaakportaal-mijngemeente`, `gis-integration`, `federated-case-collaboration`, `inspection-checklists`.
