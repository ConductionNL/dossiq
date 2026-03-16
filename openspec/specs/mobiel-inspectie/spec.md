# Mobiel Inspectie Specification

## Purpose

Mobiel Inspectie provides field inspectors with a Progressive Web App (PWA) for conducting inspections on location. Inspectors need to complete checklists, take photos, capture GPS coordinates, and add observations -- often in areas with poor or no connectivity. The app syncs data when back online.

**Tender demand**: 16% of tenders (11/69) explicitly require mobile inspection. It is a critical differentiator for VTH tenders -- mobile inspection is the primary tool for field inspectors at omgevingsdiensten.
**Standards**: PWA (Progressive Web App), Service Workers (offline), Geolocation API, MediaStream API (camera)
**Feature tier**: V2 (online PWA with photo/GPS), V3 (offline capability, sync queue, field signatures)

## Requirements

---

### REQ-MOB-01: PWA Installation and Access

**Feature tier**: V2

The system MUST provide a Progressive Web App that inspectors can install on mobile devices and access from the browser.

#### Scenario MOB-01a: Install PWA on mobile device

- GIVEN an inspector accessing Procest from a mobile browser (Chrome/Safari)
- WHEN the inspector navigates to the inspectie module
- THEN the browser MUST offer "Add to Home Screen" via the PWA manifest
- AND the installed app MUST launch in standalone mode (no browser chrome)
- AND the app MUST use the Nextcloud authentication token for secure access

#### Scenario MOB-01b: Responsive layout for mobile

- GIVEN a screen width of 375px (mobile phone)
- WHEN the inspector views a case or checklist
- THEN all UI elements MUST be usable without horizontal scrolling
- AND touch targets MUST be at least 44x44px (WCAG 2.5.5)
- AND the primary actions (complete item, take photo, add note) MUST be accessible within one tap

---

### REQ-MOB-02: Inspection Task List

**Feature tier**: V2

The system MUST display the inspector's assigned inspection tasks for the day or period.

#### Scenario MOB-02a: Today's inspections

- GIVEN inspector "Pieter" with 4 inspections scheduled for today:
  - 09:00 Bouwtoezicht fase 1 -- Keizersgracht 100
  - 10:30 Milieucontrole -- Industrieweg 5
  - 13:00 Bouwtoezicht fase 2 -- Prinsengracht 50
  - 15:00 Horeca-inspectie -- Leidseplein 12
- WHEN Pieter opens the app
- THEN the task list MUST show all 4 inspections ordered by time
- AND each item MUST show: address, type, case reference, time
- AND each item MUST have a "Navigeer" button that opens the address in the device's map app

---

### REQ-MOB-03: Checklist Completion

**Feature tier**: V2

The system MUST support completing inspection checklists on the mobile device.

#### Scenario MOB-03a: Complete a checklist item

- GIVEN a checklist "Bouwtoezicht fase 1" with 8 items
- WHEN the inspector selects item "Fundering conform tekening"
- THEN the inspector MUST be able to select: Conform / Niet-conform / Niet van toepassing
- AND add a free-text toelichting
- AND the progress indicator MUST update: "3/8 items completed"

#### Scenario MOB-03b: Mandatory photo on non-conformity

- GIVEN a checklist item configured with "foto verplicht bij niet-conform"
- WHEN the inspector marks the item as "Niet-conform"
- THEN the system MUST require at least one photo before the item can be saved
- AND the photo MUST be captured via the device camera (not from gallery, for evidentiary integrity)

---

### REQ-MOB-04: Photo and Document Capture

**Feature tier**: V2

The system MUST support capturing photos and attaching them to the inspection case.

#### Scenario MOB-04a: Take inspection photo

- GIVEN an active inspection on case "2026-089"
- WHEN the inspector taps "Foto maken"
- THEN the device camera MUST open
- AND after capturing, the photo MUST be linked to: the case, the specific checklist item (if applicable), GPS coordinates, timestamp
- AND the photo MUST be uploaded to Nextcloud Files under the case folder

#### Scenario MOB-04b: Annotate photo

- GIVEN a captured photo of a building facade
- WHEN the inspector taps "Markeren"
- THEN the inspector MUST be able to draw arrows or circles on the photo to highlight issues
- AND the annotated version MUST be saved alongside the original

---

### REQ-MOB-05: GPS Location Capture

**Feature tier**: V2

The system MUST capture GPS coordinates during inspections for geographic verification.

#### Scenario MOB-05a: Automatic location recording

- GIVEN an inspector starting an inspection at Keizersgracht 100
- WHEN the inspection is opened
- THEN the system MUST request GPS permission and record the current coordinates
- AND the coordinates MUST be stored on the inspection record (latitude, longitude, accuracy)
- AND if GPS is unavailable, the system MUST allow manual location confirmation

#### Scenario MOB-05b: Location mismatch warning

- GIVEN an inspection planned for Keizersgracht 100 (52.3676, 4.8913)
- AND the inspector's GPS shows coordinates >500m from the planned location
- THEN the system MUST display a warning: "Uw locatie wijkt af van het inspectieadres"
- AND the inspector MUST confirm to proceed

---

### REQ-MOB-06: Offline Capability

**Feature tier**: V3

The system MUST support offline operation for areas with no network connectivity. Data MUST be queued locally and synced when connectivity returns.

#### Scenario MOB-06a: Work offline

- GIVEN the inspector has loaded today's inspections while online
- WHEN network connectivity is lost
- THEN the inspector MUST still be able to: view assigned inspections, complete checklist items, take photos, add notes
- AND a "Offline" indicator MUST be shown in the app header
- AND all changes MUST be stored in the browser's IndexedDB

#### Scenario MOB-06b: Sync when back online

- GIVEN 2 inspections completed offline with 6 photos and 16 checklist responses
- WHEN network connectivity is restored
- THEN the system MUST automatically sync all queued data to the server
- AND a sync progress indicator MUST show: "Synchroniseren: 6/6 foto's, 16/16 checklistitems"
- AND any sync conflicts MUST be flagged for manual resolution (server data wins by default)

---

### REQ-MOB-07: Inspection Report Generation

**Feature tier**: V2

The system MUST support generating an inspection report from the completed checklist.

#### Scenario MOB-07a: Generate report on completion

- GIVEN an inspection with all checklist items completed
- WHEN the inspector taps "Inspectie afronden"
- THEN the system MUST generate a PDF report containing: inspecteur, datum/tijd, locatie, per checklist item: resultaat + toelichting + foto's, overall conclusie
- AND the report MUST be stored as a document on the case
- AND the case status MAY automatically advance (if configured)

## Dependencies

- **VTH Module spec** (`../vth-module/spec.md`): Inspection checklists are defined per VTH case type.
- **Case Management spec** (`../case-management/spec.md`): Inspections are tasks/activities within cases.
- **Task Management spec** (`../task-management/spec.md`): Inspection tasks appear in the inspector's task list.
- **Docudesk**: PDF report generation from checklist data.
- **Nextcloud Files**: Photo storage under case folder structure.

### Current Implementation Status

**Not yet implemented.** No mobile inspection, PWA, checklist, or field inspection code exists in the Procest codebase. There are no inspection schemas, no PWA manifest, no service worker, and no mobile-specific Vue components.

**Foundation available:**
- Task management infrastructure (`src/views/tasks/TaskList.vue`, `src/views/tasks/TaskDetail.vue`, `src/services/taskApi.js`, `src/utils/taskLifecycle.js`) provides the task list model that inspection assignments could use.
- File upload support via `filesPlugin` in the object store for photo attachment.
- The `CnDetailPage` component used in case/task detail views provides sidebar and responsive layout foundations.
- Nextcloud Files integration for document storage under case folders.
- Docudesk (external dependency) for PDF report generation from checklist data.

**Partial implementations:** None.

### Standards & References

- **PWA (Progressive Web App)**: W3C standard for installable web apps with offline capability.
- **Service Workers**: Browser API for offline caching and background sync (required for V3 offline capability).
- **IndexedDB**: Browser storage API for offline data persistence.
- **Geolocation API**: W3C standard for GPS coordinate capture.
- **MediaStream API (getUserMedia)**: Browser API for camera access.
- **WCAG 2.5.5**: Touch target size minimum 44x44px for mobile accessibility.
- **GEMMA VTH-referentiecomponenten**: Mobile inspection is part of the VTH reference architecture.
- **Omgevingswet**: Environmental and planning law requiring field inspections for permit compliance.
- **BIO**: Security requirements for mobile device data handling (device encryption, secure data transmission).

### Specificity Assessment

This spec is well-defined for V2 (online PWA) and V3 (offline) with clear scenarios for each capability. The PWA approach is well-suited to the Nextcloud web architecture.

**What's missing:**
- No OpenRegister schema definition for inspection, checklist, or checklist item entities.
- No specification of the PWA manifest configuration (icons, colors, orientation, display mode).
- No specification of how checklists are defined per case type (admin configuration UI).
- No specification of offline storage capacity limits and cleanup strategy.
- No specification of the photo annotation tool implementation (canvas-based, third-party library).
- No specification of the sync conflict resolution UI.
- No specification of how inspection tasks are created and assigned (admin UI or automatic from case workflow).
- No specification of the inspection report template format.

**Open questions:**
1. Should the mobile inspection be a separate Vue app (PWA entry point) or part of the main Procest app with responsive layout?
2. How are checklists defined -- as OpenRegister schemas, JSON templates, or n8n workflow definitions?
3. What is the maximum number of photos per inspection (storage/bandwidth considerations)?
4. Should the system support digital signatures for field sign-off (V3 feature mentioned but not specified)?
5. How does offline sync handle photo uploads -- queue all or sync progressively?
6. Should the PWA support multiple simultaneous offline inspections?
