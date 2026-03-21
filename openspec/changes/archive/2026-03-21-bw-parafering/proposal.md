# B&W Parafering & Besluitvorming Specification

## Problem
B&W parafering covers the ambtelijk (civil servant) workflow for preparing, reviewing, and approving proposals before they reach the College van B&W for formal decision-making. The bestuurlijk (political) part -- agenda management, vergadering, and besluitenlijst -- is handled by external RIS systems (iBabs, NotuBiz). This spec covers the ambtelijk workflow and the connector to the RIS.
**Tender demand**: Found in 20+ tenders (29% of all, higher among generic zaaksysteem tenders). B&W besluitvorming is the #6 Nice-to-have but weighs heavily in scoring (typically 3-8% of total score, up to 68 points).
**Standards**: BPMN 2.0 (process modeling), ZGW Besluiten API, ZDS (Zaak-Document Services for legacy RIS), CMMN 1.1 (HumanTask for parafering steps)
**Feature tier**: V1 (ambtelijk parafering, sequential routing, audit trail), V2 (parallel parafering, mobile parafering, iBabs/NotuBiz connector, vergaderbeheer)
**Competitive context**: Dimpact ZAC implements decision management via the ZGW BRC API with besluittype validation, publication date handling, and document linking. ZAC does NOT include B&W parafering workflow -- that is handled externally. Flowable's CMMN engine can model parafeerroutes as sequential/parallel HumanTasks with configurable completion rules. ArkCase and CaseFabric both provide full approval workflows with configurable routing. Procest should implement parafering as OpenRegister objects with task-based routing, leveraging the existing task management infrastructure.

## Proposed Solution
Implement B&W Parafering & Besluitvorming Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the bw-parafering specification.

## Success Criteria
#### Scenario BW-01a: Create college voorstel
#### Scenario BW-01b: Voorstel types
#### Scenario BW-01c: Voorstel from case dashboard panel
#### Scenario BW-01d: Multiple voorstellen per case
#### Scenario BW-01e: Pre-fill voorstel from case data
