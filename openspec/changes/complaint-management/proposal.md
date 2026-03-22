# Complaint Management Implementation

## Problem
Dutch municipalities need Awb chapter 9 compliant complaint handling with dedicated lifecycle, escalation to formal cases, disposition tracking, and frequency analysis. No complaint-specific infrastructure exists in Procest.

## Proposed Solution
Implement complaint management as a first-class entity in Procest using OpenRegister schemas for complaints, hearings, and dispositions. Add Vue components for complaint list, detail, and dashboard widgets. Integrate with existing case infrastructure for escalation.

## Scope
- New OpenRegister schemas: `complaint`, `hearing`, `complaintDisposition`, `complaintCategory`
- New Vue components: `ComplaintList.vue`, `ComplaintDetail.vue`, `ComplaintDashboardWidget.vue`
- Backend: Config keys in SettingsService, router entries
- Integration: DeadlinePanel reuse, ActivityTimeline, case escalation links

## Out of Scope
- Bezwaarschriften, ombudsman case management, AI classification, citizen portal
