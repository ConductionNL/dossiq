# My Work (Werkvoorraad) Specification

## Problem
My Work is the personal productivity hub for case handlers. It aggregates all work items assigned to the current user -- cases where they are the handler and tasks assigned to them -- into a single prioritized view. Items are grouped by urgency (Overdue, Due This Week, Upcoming, No Deadline) and sorted by priority then deadline within each group. This view answers the daily question: "What do I need to work on next?"
**Feature tiers**: MVP (cases + tasks, filter tabs, sorting, grouping, overdue highlighting, item navigation, empty state); V1 (cross-app workload with Pipelinq, show completed toggle, dashboard widgets)
**Competitive context**: Dimpact ZAC provides a customizable drag-and-drop dashboard with signaling cards (notifications for overdue items, new documents, etc.) and configurable worklist tables with WebSocket-based real-time updates. xxllnc Zaken uses phase-bound task lists where tasks are automatically generated from case type definitions. ArkCase provides configurable dashboard widgets with queue-based worklists powered by Drools routing rules. Flowable offers a unified task inbox across BPMN and CMMN engines with claiming, delegation, and real-time push. Procest takes a simpler approach: a static aggregation view that queries OpenRegister for assigned cases and tasks, groups by urgency, and provides clear navigation to detail views.

## Proposed Solution
Implement My Work (Werkvoorraad) Specification following the detailed specification. Key requirements include:
- See full spec for detailed requirements

## Scope
This change covers all requirements defined in the my-work specification.

## Success Criteria
- View assigned cases and tasks
- Case item display
- Task item display
- Filter tab layout
- Filter by Cases only
