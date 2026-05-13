# Doorlooptijd Dashboard (Processing Time Analytics)

**Issue:** #89
**Branch:** `feature/89/doorlooptijd-dashboard`

## Overview

Adds a dedicated Doorlooptijd (processing time) analytics view to the Procest dashboard, enabling case managers and team leads to monitor SLA adherence, identify processing bottlenecks, and track compliance trends. Dutch regulations (Awb, Woo) impose strict processing deadlines, making this view essential for proactive case management.

## Features

- **SLA compliance rate widget**: Percentage of cases completed within their `processingDeadline`, broken down by case type
- **Processing time distribution chart**: Histogram of actual vs. allowed processing days per case type
- **Trend line chart**: Monthly SLA compliance rate over the last 12 months (ApexCharts)
- **At-risk cases panel**: Open cases where remaining time is less than 25% of the allowed processing deadline
- **Average processing time table**: Per-case-type performance table with SLA target comparison
- **Dashboard KPI card**: Summary SLA compliance card added to the main dashboard KPI row

## Data Sources

All data derived from existing case fields: no schema changes required:
- `case.startDate`, `case.endDate`, `case.deadline`, `case.plannedEndDate`, `case.status`, `case.caseType`
- `caseType.processingDeadline`
- `statusType.isFinal`

## Standards

- Awb (Algemene wet bestuursrecht) processing deadline compliance
- Woo (Wet open overheid) 4-week response mandate
- ZGW Zaken API `einddatum` and `uiterlijkeEinddatumAfdoening` fields
