# Proposal: supervision-plan-page

## Why

Requirement REQ-VTH-05 (vth-module) says the system supports annual supervision plans, and board DqToezichtplan draws the page:
- the header "Supervision plan 2026", with Download and "Suggest a schedule";
- the subtitle "Annual supervision plan: 150 inspections across hospitality, construction and environment", a Year | Quarter switch, and "Set categories and capacity";
- four figures: Scheduled, Completed, Completion, Capacity and demand;
- "Planned and completed per category", with a progress bar per category;
- a quarterly overview (scheduled, completed, cancelled, remaining, forecast);
- "Frequency by risk", with a proposal on capacity;
- the planned inspections on a map, in three proposed clusters.

dossiq has no such page. On the demo stack (24 Nov 2026) the route the boards name falls back to My work, and no menu entry leads to a plan.

## What changes

- A Supervision plan page (`/inspection-plan`), as manifest configuration over the library's dashboard, chart, table and map widgets, reading the plan from the register.
- A plan record and a category row (schema `supervisionPlan`, `supervisionPlanCategory`): year, categories with planned count, capacity hours and risk frequency. Completed and cancelled are counted from the inspections themselves, never stored twice.
- "Suggest a schedule" proposes dates from the frequency per risk profile and the capacity, and shows the proposal before anything is written.
- The page is reached from the Field inspection page and from the menu for the supervision role.

## Out of scope

- Planning individual inspections (Field inspection).
- Enforcement strategy (REQ-VTH-04).
