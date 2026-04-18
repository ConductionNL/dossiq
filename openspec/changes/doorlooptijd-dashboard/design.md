# Doorlooptijd Dashboard

**Status**: pr-created

## Summary

Add processing time tracking and reporting capabilities to Procest through dashboard widgets and analytics views. This covers measuring actual case processing times against configured SLA targets, identifying bottlenecks in process steps, and providing management reporting on throughput and adherence -- all without requiring external BI tools.

The dashboard complements signalering-widgets (which alert on individual cases) by providing aggregate analytics and trend visualization across cases and zaaktypen.

## Demand Evidence

### Cluster Data (from market intelligence DB)

| Cluster | Requirements | Tenders |
|---------|-------------|---------|
| Doorlooptijd (throughput time) tracking | 125 | 77 |
| Reporting / rapportage | 1,172 | 295 |
| **Total** | **1,297** | **~350 unique** |

### Top Tenders

| Tender | Organisation | URL |
|--------|-------------|-----|
| Levering en implementatie en technisch beheer van een ERP-systeem | Rijnvicus B.V. | https://www.tenderned.nl/aankondigingen/overzicht/398950 |
| Telefonie en Communicatiediensten | Sociale Verzekeringsbank | https://www.tenderned.nl/aankondigingen/overzicht/265055 |
| Customer Service Platform CIBG | Ministerie van VWS | https://www.tenderned.nl/aankondigingen/overzicht/414529 |
| CRM functionaliteit UWV | UWV | https://www.tenderned.nl/aankondigingen/overzicht/285324 |
| Basis ICT/IV Voorzieningen MSP en MSSP | Stichting Projectenbureau Publieke Gezondheid | https://www.tenderned.nl/aankondigingen/overzicht/411356 |

### Representative Requirements from Tenders

1. "Binnen vooraf gedefinieerde doorlooptijden afgehandeld."
2. "De opdrachtgever kan zonder tussenkomst van de leverancier zelf rapportages vanuit de oplossing creeren. De oplossing heeft functionaliteiten om met query's lijsten en selecties samen te stellen en geëxporteerd worden voor verdere externe verwerking."
3. "Vanuit de Oplossing kan eenvoudig een gemaakte rapportage worden geexporteerd voor verdere externe verwerking."
4. "Planning en verwachte doorlooptijd."
5. "De Oplossing kent een interface waarin grafisch wordt weergegeven: Trendanalyses, Termijnbewaking, Procesvoortgang."
6. "Het is mogelijk om te rapporteren over het aantal actieve gebruikers in het platform. Hier kan men filteren op periode, organisatie, opvang/school en rol."
7. "Alle rapportages binnen overeengekomen servicelevel beschikbaar via portaal."
8. "De opdrachtnemer zorgt maandelijks voor een schriftelijke rapportage betreffende de storingsmeldingen en de verrichte oplossingen."

## Scope

### In Scope

- **Doorlooptijd tracking**: Automatic measurement of elapsed time per case, per process step, and per status -- accounting for opschorting (suspension) periods
- **SLA configuration**: Define target doorlooptijden per zaaktype and per process step (streeftermijn and fatale termijn)
- **SLA adherence visualization**: Dashboard showing percentage of cases within SLA per zaaktype, with trend lines over configurable periods
- **Process step bottleneck analysis**: Identify which process steps take the longest on average, highlighting bottlenecks
- **Dashboard widgets**: Nextcloud Dashboard widgets for doorlooptijd KPIs (average processing time, SLA adherence %, overdue count)
- **Management rapportage view**: Dedicated reporting page with filterable charts (by zaaktype, team, period, status)
- **Trend analysis**: Historical trend charts showing processing time evolution over weeks/months
- **Export functionality**: Export reports as CSV/Excel for further analysis in external tools
- **Configurable report builder**: Allow administrators to create custom reports by selecting dimensions (zaaktype, team, period) and measures (average doorlooptijd, count, SLA %)

### Out of Scope

- Real-time alerting on individual case deadlines (covered by `signalering-widgets`)
- External BI tool integrations like PowerBI, Tableau, Qlik (future enhancement)
- Financial reporting on case costs (ERP domain)
- Performance/load monitoring of the system itself (infrastructure concern)

## Dependencies

- **workflow-engine-enhancement** (REQUIRED): Doorlooptijd data comes from status transition timestamps in the workflow engine
- **signalering-widgets** (RECOMMENDED): Deadline data model shared with signalering
- **Nextcloud Dashboard**: IDashboardWidget for KPI widgets
- **MyDash** (OPTIONAL): For richer chart rendering (ApexCharts via @conduction/nextcloud-vue)
- **OpenRegister**: Case and status data queried from OpenRegister

## Acceptance Criteria

1. GIVEN cases with completed workflows, WHEN a manager opens the doorlooptijd dashboard, THEN they see average processing times per zaaktype with comparison against configured SLA targets
2. GIVEN an SLA configuration per zaaktype, WHEN cases are completed, THEN the system automatically calculates and displays SLA adherence percentage (cases within target vs. total)
3. GIVEN multiple completed cases, WHEN a manager views the bottleneck analysis, THEN they see which process steps have the highest average duration, highlighting potential improvement areas
4. GIVEN a configurable time period, WHEN a manager views trend analysis, THEN they see doorlooptijd trends over weeks/months with visual indicators for improvement or deterioration
5. GIVEN the Nextcloud Dashboard, WHEN a user adds doorlooptijd widgets, THEN they see KPI cards showing current SLA adherence, average processing time, and overdue case count for their scope
6. GIVEN a management report, WHEN a user applies filters (zaaktype, team, period), THEN the charts and tables update to reflect the filtered data
7. GIVEN a completed report view, WHEN a user exports the data, THEN a CSV/Excel file is generated with the displayed data including all applied filters
8. GIVEN a case with an opschorting period, WHEN doorlooptijd is calculated, THEN the suspended period is excluded from the elapsed time calculation
