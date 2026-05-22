# Bezwaar en Beroep Workflow

## Placement & Information Architecture

**Placement type:** `SUB_PAGE` — Sub-page beneath a top-level menu entry. Renders as a page inside the parent surface (usually reachable via a router child route or a tab on the parent index page).

**Lives at:** Bezwaar & beroep › Bezwaren

**Rationale:** Workflow-keten.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Summary

Configure the Procest workflow engine (from `workflow-engine-enhancement`) with workflows for the objection and appeal process (bezwaar en beroep). This covers the full lifecycle from receiving an objection against a government decision, through hearing (hoorzitting), advisory committee (bezwaarschriftencommissie), to the final decision on the objection -- all in compliance with the Algemene wet bestuursrecht (AWB).

This is primarily a workflow configuration change with targeted extensions for AWB-specific requirements like statutory deadlines, hearing scheduling, and dossier sharing with legal departments.

## Demand Evidence

### Cluster Data (from market intelligence DB)

| Cluster | Requirements | Tenders |
|---------|-------------|---------|
| Bezwaar en beroep (objection/appeal) | 801 | 209 |
| AWB (Algemene wet bestuursrecht) | 269 | 127 |
| **Total** | **1,070** | **~280 unique** |

### Top Tenders

| Tender | Organisation | URL |
|--------|-------------|-----|
| Zaaksysteem | Werkorganisatie HLT Samen | https://www.tenderned.nl/aankondigingen/overzicht/399455 |
| Geintegreerd Zaaksysteem met KCS-functionaliteit | Gemeente Beverwijk | https://www.tenderned.nl/aankondigingen/overzicht/411386 |
| Zaaksysteem gemeente Winterswijk | Gemeente Winterswijk | https://www.tenderned.nl/aankondigingen/overzicht/198896 |
| Zaaksysteem (met geintegreerd DMS en RMA) | Gemeente Nissewaard | https://www.tenderned.nl/aankondigingen/overzicht/257916 |
| Het leveren van een zaaksysteem | Gemeente Den Helder | https://www.tenderned.nl/aankondigingen/overzicht/267874 |
| De levering, onderhoud en implementatie van een SaaS-oplossing voor een Zaaksysteem | Gemeente Horst aan de Maas | https://www.tenderned.nl/aankondigingen/overzicht/345060 |

### Representative Requirements from Tenders

1. "Dossier digitaal kunnen delen met o.a. de afdeling Juridische Zaken voor behandeling in bezwaar- en beroep."
2. "Toestemming vragen voor het inschakelen van subverwerkers is een wens. Leverancier informeert opdrachtgever schriftelijk; opdrachtgever kan bezwaar aantekenen."
3. "Hoorzittingen en rechtszaken -- Met enige regelmaat komt het voor dat een aanvrager in beroep gaat tegen een door de Opdrachtgever genomen besluit."
4. "De gevolgen van een bezwaar- en beroepsafhandeling op objectgegevens moet automatisch verwerkt worden in het WOZ-object voor het komende jaar en - indien van toepassing - automatisch worden verwerkt naar Heffen en Innen (automatisch verminderen)."
5. "IPO kan verwerker schriftelijk opdracht geven om zelf aan het verzoek of bezwaar gevolg te geven. Verwerker zal IPO op haar eerste verzoek door middel van passende technische en organisatorische maatregelen bijstand verlenen."

## Scope

### In Scope -- Configuration (using workflow engine)

- **Bezwaar zaaktype template**: Pre-configured zaaktype for bezwaarbehandeling with AWB-compliant process steps
- **Bezwaar process steps**: Ontvangst, Ontvankelijkheidstoets, Hoorzitting plannen, Hoorzitting, Advies commissie, Beslissing op bezwaar, Bekendmaking
- **Bezwaar status transitions**: With AWB-mandated pre-conditions (e.g., ontvankelijkheid must be determined before hearing)
- **Beroep zaaktype template**: Configuration for cases escalated to administrative court (bestuursrechter)
- **AWB deadline configuration**: Statutory 6-week decision deadline with configurable extension (verdaging) and suspension (opschorting)

### In Scope -- Extra functionality beyond generic workflows

- **Primair besluit linking**: Link bezwaar zaak to the original decision (primair besluit) that is being contested, with automatic cross-referencing
- **Hoorzitting scheduling**: Integration with Nextcloud Calendar for hearing scheduling with participant invitations
- **Dossier compilation**: Automated compilation of the bezwaar dossier from the original case documents plus bezwaar-specific documents
- **Bezwaarschriftencommissie support**: Advisory track with external committee members, opinion document workflow
- **AWB deadline engine**: Specific AWB deadlines (6 weeks for beslissing, extension rules, opschorting triggers) pre-configured in the workflow with automatic termijnbewaking
- **Beroep dossier export**: Export complete dossier for submission to administrative court

### Out of Scope

- Generic workflow engine (covered by `workflow-engine-enhancement`)
- Signalering/alerts for deadlines (covered by `signalering-widgets` -- but AWB deadlines must be trackable)
- WOZ/tax-specific bezwaar processing (domain-specific to tax systems)

## Dependencies

- **workflow-engine-enhancement** (REQUIRED): This change configures the workflow engine
- **Procest case management**: Bezwaar creates a new case linked to the original case
- **deelzaak-support** (RECOMMENDED): Bezwaar is often modeled as a related case to the primair besluit
- **Nextcloud Calendar**: Hoorzitting scheduling
- **Nextcloud Files**: Dossier document management

## Acceptance Criteria

1. GIVEN the workflow engine with bezwaar templates installed, WHEN a citizen files an objection against a decision, THEN a bezwaar case is created linked to the original decision case with the AWB-compliant workflow activated
2. GIVEN a bezwaar case, WHEN the ontvankelijkheidstoets step is completed as "ontvankelijk", THEN the workflow progresses to hearing scheduling and the 6-week AWB deadline starts
3. GIVEN a bezwaar case with a 6-week deadline, WHEN the deadline approaches (configurable threshold), THEN the system provides deadline tracking data that the signalering system can use for alerts
4. GIVEN a bezwaar case, WHEN the handler needs to extend the deadline (verdaging), THEN they can register the extension with reason and the deadline is automatically recalculated per AWB rules
5. GIVEN a bezwaar case requiring a hearing, WHEN the handler schedules a hoorzitting, THEN a calendar event is created with invitations to all parties (bezwaarmaker, vertegenwoordiger, commissieleden)
6. GIVEN a completed bezwaar procedure, WHEN the handler needs to share the dossier with Juridische Zaken or court, THEN the system compiles all relevant documents (original decision, bezwaarschrift, hearing report, advice, beslissing) into an exportable dossier
7. GIVEN a beslissing op bezwaar that overturns the original decision, WHEN the handler registers the outcome, THEN the original case is updated to reflect the bezwaar outcome
8. GIVEN a bezwaar case that escalates to beroep, WHEN the handler creates a beroep case, THEN the beroep case inherits the dossier and links to both the bezwaar and the original decision
