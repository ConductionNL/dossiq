# doorlooptijd-dashboard Delta

## ADDED Requirements

### Requirement: The processing-time averages can be grouped per week, month, quarter or year

The metrics endpoint SHALL accept `granularity` of `week`, `month`, `quarter` or `year` (default `month`) and SHALL
group the averages in `summary.monthlyAvgDays` by it over the period window, oldest group first and today's group last,
labelled `YYYY-Www`, `YYYY-MM`, `YYYY-Qn` or `YYYY`, naming the grouping in `summary.granularity`. Any other value SHALL
be refused with 400. The processing-time page SHALL offer Maand, Kwartaal and Jaar as the grouping, open on Maand, and
keep the one-year window.

#### Scenario: A year per quarter

- **GIVEN** cases closed in February, July and September 2026, read on 16 October 2026
- **WHEN** the metrics are read with `granularity=quarter`
- **THEN** the groups are 2025-Q4 to 2026-Q4, with the averages in Q1 and Q3
