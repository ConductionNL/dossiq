# Signalering Widgets

## Summary

Add alert and reminder widgets to Procest for case deadlines, overdue tasks, and configurable triggers. This covers two related but distinct concerns: termijnbewaking (deadline monitoring) is part of the workflow engine's data layer, while signalering is the notification and widget layer that surfaces deadline information to users through dashboard widgets, in-app alerts, and external notifications (email).

The widget layer reads deadline data from the workflow engine and presents it in actionable views.

## Demand Evidence

### Cluster Data (from market intelligence DB)

| Cluster | Requirements | Tenders |
|---------|-------------|---------|
| Signalering (alerting/reminders) | 280 | 120 |
| Termijnbewaking (deadline monitoring) | 151 | 70 |
| **Total** | **431** | **~160 unique** |

### Top Tenders

| Tender | Organisation | URL |
|--------|-------------|-----|
| Levering en ondersteuning nieuw VTH systeem op basis van SaaS | Omgevingsdienst Noordzeekanaalgebied | https://www.tenderned.nl/aankondigingen/overzicht/308208 |
| Levering en implementatie en technisch beheer van een ERP-systeem | Rijnvicus B.V. | https://www.tenderned.nl/aankondigingen/overzicht/398950 |
| Marktconsultatie Contract- en Leveranciersmanagementsysteem | Gemeente Sudwest-Fryslan | https://www.tenderned.nl/aankondigingen/overzicht/416075 |
| Belastingen-software | Gemeente Opmeer | https://www.tenderned.nl/aankondigingen/overzicht/415795 |

### Representative Requirements from Tenders

1. "De oplossing biedt signaleringsfunctionaliteiten op basis van de status van een actie/zaak."
2. "Toon hoe de behandelaar de termijn kan opschorten en hoe de relatie wordt gelegd met de signalering van de zaak."
3. "Workflows kunnen getriggerd worden door taken of events die binnen de Oplossing optreden. Hierbij valt te denken aan de afloop van een datum, bijvoorbeeld de signaleringsdat..."
4. "In de Oplossing is het mogelijk om per gebruiker en gebruikersgroep in te stellen dat er een signalering plaatsvindt via de mail bij de registratie van een nieuwe zaak."
5. "In de Oplossing is het mogelijk om per gebruikersgroep in te stellen dat er een signalering plaatsvindt buiten de Oplossing om bij de registratie van een nieuwe zaak."
6. "Gebruikers krijgen automatisch, bijvoorbeeld per e-mail, een signalering in geval van relevante wijzigingen in zaken zonder eerst het systeem te moeten openen."
7. "Toon de signalering voor nieuwe informatie/berichtgeving bij de betreffende zaak."
8. "De Oplossing kent een interface waarin grafisch wordt weergegeven: Trendanalyses, Termijnbewaking, Procesvoortgang."
9. "Visuele signalering van contractstatus (bijv. kleuren voor aflopende contracten, rood = bijna verlopen)."

## Scope

### In Scope

- **Deadline monitoring data model**: Track streeftermijn (target) and fatale termijn (hard deadline) per case, with support for opschorting (suspension) and verlenging (extension) that recalculate dates
- **Signalering configuration**: Per zaaktype, configure which events trigger alerts and at what thresholds (e.g., 7 days before deadline, on deadline, overdue)
- **Dashboard widgets**: Nextcloud Dashboard widgets showing upcoming deadlines, overdue cases, and cases requiring attention -- with color coding (green/orange/red)
- **In-app notifications**: Nextcloud notification integration (INotificationManager) for deadline alerts visible in the notification bell
- **Email notifications**: Configurable email alerts for deadlines sent via n8n (per user/group preference)
- **Werkvoorraad signalering**: Visual indicators in the work queue (werkvoorraad) showing deadline status per case with color coding
- **Case detail signalering**: Timeline/banner on the case detail view showing upcoming and passed deadlines
- **Bulk deadline overview**: Management view showing all cases with their deadline status across zaaktypen

### Out of Scope

- The workflow engine itself (covered by `workflow-engine-enhancement`)
- Doorlooptijd analytics and SLA reporting (covered by `doorlooptijd-dashboard`)
- Domain-specific deadline rules like AWB 6-week bezwaar deadline (configured in respective workflow changes)

## Dependencies

- **workflow-engine-enhancement** (REQUIRED): Deadline data comes from the workflow engine's status/step model
- **Nextcloud Notifications**: INotificationManager for in-app alerts
- **Nextcloud Dashboard**: IDashboardWidget for dashboard integration
- **n8n**: Email notification delivery
- **MyDash** (OPTIONAL): For richer dashboard widget rendering

## Acceptance Criteria

1. GIVEN a case with a configured fatale termijn, WHEN the current date crosses the configured warning threshold (e.g., 7 days before), THEN the case handler receives an in-app notification and the case shows an orange indicator in the werkvoorraad
2. GIVEN a case that has passed its fatale termijn, WHEN a user views the werkvoorraad, THEN the case shows a red overdue indicator and the handler receives escalation notifications
3. GIVEN a signalering configuration per zaaktype, WHEN an administrator configures alert thresholds and notification channels, THEN they can set different thresholds for streeftermijn and fatale termijn with per-channel preferences (in-app, email, both)
4. GIVEN a user with cases approaching deadlines, WHEN they view the Nextcloud Dashboard, THEN a widget shows their upcoming deadlines sorted by urgency with color coding
5. GIVEN a case where the termijn is suspended (opschorting), WHEN the suspension is registered, THEN all deadline calculations and signaleringen are automatically adjusted
6. GIVEN a user preference for email notifications, WHEN a signalering is triggered, THEN an email is sent via n8n with case details and a direct link to the case
7. GIVEN a management user, WHEN they open the bulk deadline overview, THEN they see all cases across zaaktypen with deadline status, filterable by team, zaaktype, and urgency level
8. GIVEN new information on a case (new document, status change, deelzaak completion), WHEN the event matches a signalering trigger, THEN the case handler sees a visual indicator on the case in both werkvoorraad and case detail view
