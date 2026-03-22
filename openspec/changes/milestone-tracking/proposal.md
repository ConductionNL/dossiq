## Why

Technical workflow states are meaningless to end users. Milestones translate process progress into language everyone understands. This implements configurable milestone sets per case type, automatic/manual marking, visual progress indicators, duration analytics, and a milestone API.

## What Changes

1. Milestone schema in procest_register.json (milestoneDefinition, milestoneRecord)
2. MilestoneService for milestone CRUD and progress calculation
3. MilestoneProgress Vue component (step indicator in case detail)
4. MilestoneProgressBar Vue component (compact progress in case list)
5. Milestone configuration tab in case type admin
6. API endpoint for milestone data

## Impact

- New schemas, service, 3 Vue components, route additions
- Extends case detail view and case list with progress indicators
