# process-mining-bottlenecks

## ADDED Requirements

### Requirement: Process analytics shows dwell time as a heatmap (REQ-CRS-36)

The process analytics page SHALL show a heatmap of statuses against weeks, shaded by median dwell time. Each cell SHALL name its value when read.

Rows: `10.4` (dossiq matrix).

#### Scenario: A slow week stands out
- **GIVEN** cases that waited far longer in Review in week 12 than in other weeks
- **WHEN** you open the heatmap
- **THEN** the Review cell for week 12 is the darkest and names its median
