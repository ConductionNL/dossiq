# Tasks: doorlooptijd-dashboard

## 1. Backend Services

### Task 1: Create DoorlooptijdService for processing time calculation
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#acceptance-criteria`
- **files**: `lib/Service/DoorlooptijdService.php`
- **acceptance_criteria**:
  - Calculate elapsed time for cases accounting for opschorting (suspension) periods
  - Calculate per-case processing time
  - Calculate per-process step duration
  - Calculate SLA adherence (cases within SLA vs total)
  - Support multiple time dimensions: case total, per status, per step
- [x] Create DoorlooptijdService

### Task 2: Create SlaConfigurationService for SLA management
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#scope`
- **files**: `lib/Service/SlaConfigurationService.php`
- **acceptance_criteria**:
  - Retrieve SLA configuration per zaaktype from OpenRegister
  - Support streeftermijn (target time) and fatale termijn (deadline)
  - Support per-process-step SLA targets
  - Return SLA config in standardized format
- [x] Create SlaConfigurationService

### Task 3: Create BottleneckAnalysisService
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#acceptance-criteria`
- **files**: `lib/Service/BottleneckAnalysisService.php`
- **acceptance_criteria**:
  - Identify process steps with highest average duration
  - Rank steps by duration for a zaaktype
  - Return bottleneck data with step name, avg duration, case count
  - Support filtering by zaaktype and date range
- [x] Create BottleneckAnalysisService

### Task 4: Create TrendAnalysisService for historical analysis
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#acceptance-criteria`
- **files**: `lib/Service/TrendAnalysisService.php`
- **acceptance_criteria**:
  - Calculate doorlooptijd trends over weeks/months
  - Return trend data with improvement/deterioration indicators
  - Support configurable time periods (weekly, monthly, quarterly)
  - Group by zaaktype or process step
- [x] Create TrendAnalysisService

### Task 5: Create ReportingService for filtered report generation
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#acceptance-criteria`
- **files**: `lib/Service/ReportingService.php`
- **acceptance_criteria**:
  - Generate management reports with configurable filters (zaaktype, team, period, status)
  - Return aggregated metrics: count, avg doorlooptijd, SLA adherence %
  - Support export data generation in structured format
  - Apply filters dynamically to all report data
- [x] Create ReportingService

## 2. Controllers

### Task 6: Create DoorlooptijdController for API endpoints
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#acceptance-criteria`
- **files**: `lib/Controller/DoorlooptijdController.php`
- **acceptance_criteria**:
  - GET /api/doorlooptijd/stats - return case processing times and SLA adherence
  - GET /api/doorlooptijd/bottlenecks - return process step bottleneck analysis
  - GET /api/doorlooptijd/trends - return historical trend data
  - All endpoints support filtering by zaaktype and date range
- [x] Create DoorlooptijdController

### Task 7: Create ReportingController for report management
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#acceptance-criteria`
- **files**: `lib/Controller/ReportingController.php`
- **acceptance_criteria**:
  - GET /api/reports/doorlooptijd - return filtered report data
  - POST /api/reports/doorlooptijd/export - export report as CSV
  - Support filter parameters: zaaktype, team, period, status
  - Return formatted chart data and table data
- [x] Create ReportingController

### Task 8: Register routes for new API endpoints
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md`
- **files**: `appinfo/routes.php`
- **acceptance_criteria**:
  - All new endpoints registered and routable
  - Endpoints follow REST conventions
  - Proper HTTP method usage (GET, POST)
- [x] Register routes in appinfo/routes.php

## 3. Dashboard Widgets

### Task 9: Create SlaAdherenceWidget
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#acceptance-criteria`
- **files**: `lib/Dashboard/SlaAdherenceWidget.php`, `js/dashboardwidgets/SlaAdherenceWidget.vue`
- **acceptance_criteria**:
  - Display SLA adherence percentage for user's scope
  - Show trend indicator (improving/declining)
  - Show case count within/outside SLA
  - Configurable by user
- [x] Create SlaAdherenceWidget PHP class
- [x] Create SlaAdherenceWidget Vue component
- [x] Register widget in service container

### Task 10: Create AverageProcessingTimeWidget
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#acceptance-criteria`
- **files**: `lib/Dashboard/AverageProcessingTimeWidget.php`, `js/dashboardwidgets/AverageProcessingTimeWidget.vue`
- **acceptance_criteria**:
  - Display average processing time in days
  - Show comparison to SLA target
  - Support filtering by zaaktype
  - Display time range covered
- [x] Create AverageProcessingTimeWidget PHP class
- [x] Create AverageProcessingTimeWidget Vue component
- [x] Register widget in service container

### Task 11: Create OverdueCountWidget
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#acceptance-criteria`
- **files**: `lib/Dashboard/OverdueCountWidget.php`, `js/dashboardwidgets/OverdueCountWidget.vue`
- **acceptance_criteria**:
  - Display count of overdue cases
  - Show cases by days overdue
  - Link to overdue case list
  - Update frequently (near deadline)
- [x] Create OverdueCountWidget PHP class
- [x] Create OverdueCountWidget Vue component
- [x] Register widget in service container

## 4. Frontend Views

### Task 12: Create DoorlooptijdDashboardPage
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#acceptance-criteria`
- **files**: `js/views/DoorlooptijdDashboard.vue`
- **acceptance_criteria**:
  - Display SLA adherence chart (line chart showing % over time)
  - Display bottleneck analysis (bar chart showing step durations)
  - Display trend analysis (multi-line chart showing improvement/deterioration)
  - Support date range picker
  - Support zaaktype filter
- [x] Create DoorlooptijdDashboard Vue view

### Task 13: Create ReportingPage
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#acceptance-criteria`
- **files**: `js/views/ReportingPage.vue`
- **acceptance_criteria**:
  - Display report with filterable data (zaaktype, team, period, status)
  - Show summary statistics in cards
  - Show detailed table of cases with doorlooptijd
  - Support column selection and sorting
  - Display chart visualization of metrics
- [x] Create ReportingPage Vue view

### Task 14: Add routes for new frontend views
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md`
- **files**: `js/router/index.js`
- **acceptance_criteria**:
  - DoorlooptijdDashboard view routable at /apps/procest/doorlooptijd
  - ReportingPage view routable at /apps/procest/reporting
  - Routes properly authenticated
- [x] Add routes in router configuration

## 5. Data Export

### Task 15: Create ExportService for CSV/Excel generation
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#acceptance-criteria`
- **files**: `lib/Service/ExportService.php`
- **acceptance_criteria**:
  - Generate CSV from report data
  - Generate Excel (XLSX) from report data
  - Include all applied filters in export
  - Format dates and numbers appropriately
  - Include summary statistics in export
- [x] Create ExportService

### Task 16: Implement export endpoint in ReportingController
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#acceptance-criteria`
- **files**: `lib/Controller/ReportingController.php`
- **acceptance_criteria**:
  - POST /api/reports/doorlooptijd/export endpoint functional
  - Supports format parameter (csv, xlsx)
  - Applies all filters before export
  - Returns properly formatted file
- [x] Implement export functionality

## 6. Configuration & Settings

### Task 17: Add doorlooptijd configuration to admin settings
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md`
- **files**: `lib/Settings/DoorlooptijdAdmin.php`, `js/settings/DoorlooptijdAdmin.vue`
- **acceptance_criteria**:
  - Allow administrators to configure SLA defaults
  - Allow setting opschorting (suspension) status identifiers
  - Allow defining custom report dimensions
  - Settings persist to OpenRegister or app config
- [x] Create DoorlooptijdAdmin settings page

## 7. Tests

### Task 18: Create DoorlooptijdService tests
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md`
- **files**: `tests/Unit/Service/DoorlooptijdServiceTest.php`
- **acceptance_criteria**:
  - Test elapsed time calculation without suspension
  - Test elapsed time calculation with suspension periods
  - Test SLA adherence percentage calculation
  - Test handling of missing SLA configuration
  - Minimum 3 test methods
- [x] Create DoorlooptijdService tests

### Task 19: Create SlaConfigurationService tests
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md`
- **files**: `tests/Unit/Service/SlaConfigurationServiceTest.php`
- **acceptance_criteria**:
  - Test retrieval of SLA config per zaaktype
  - Test handling of missing SLA config
  - Test merging of streeftermijn and fatale termijn
  - Minimum 3 test methods
- [x] Create SlaConfigurationService tests

### Task 20: Create DoorlooptijdController tests
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md`
- **files**: `tests/Unit/Controller/DoorlooptijdControllerTest.php`
- **acceptance_criteria**:
  - Test /api/doorlooptijd/stats endpoint
  - Test /api/doorlooptijd/bottlenecks endpoint
  - Test /api/doorlooptijd/trends endpoint
  - Test filter parameter handling
  - Test error responses
  - Minimum 3 test methods
- [x] Create DoorlooptijdController tests

### Task 21: Create ReportingController tests
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md`
- **files**: `tests/Unit/Controller/ReportingControllerTest.php`
- **acceptance_criteria**:
  - Test /api/reports/doorlooptijd endpoint
  - Test /api/reports/doorlooptijd/export endpoint
  - Test filter application (zaaktype, period, etc.)
  - Test CSV export format
  - Minimum 3 test methods
- [x] Create ReportingController tests

## 8. Acceptance & Quality

### Task 22: Run quality checks and fix any issues
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md`
- **files**: All new PHP files
- **acceptance_criteria**:
  - composer check:strict passes
  - All PSR-12 style compliance
  - No PHP warnings or errors
  - All tests pass
- [x] Pass quality gates

### Task 23: Verify acceptance criteria implementation
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md#acceptance-criteria`
- **files**: All
- **acceptance_criteria**:
  - AC1: Manager sees average processing times per zaaktype with SLA comparison ✓
  - AC2: System calculates and displays SLA adherence percentage ✓
  - AC3: Manager views bottleneck analysis with process steps ranked ✓
  - AC4: Manager views trend analysis over weeks/months ✓
  - AC5: User adds doorlooptijd widgets to dashboard ✓
  - AC6: Manager applies filters to reports ✓
  - AC7: Manager exports report data to CSV/Excel ✓
  - AC8: System excludes opschorting periods from calculation ✓
- [x] Verify all acceptance criteria met

## 9. Documentation & Finalization

### Task 24: Update design.md status to pr-created
- **spec_ref**: `openspec/changes/doorlooptijd-dashboard/design.md`
- **files**: `openspec/changes/doorlooptijd-dashboard/design.md`
- **acceptance_criteria**:
  - Status field updated to "pr-created"
  - PR link documented
- [x] Update design.md
