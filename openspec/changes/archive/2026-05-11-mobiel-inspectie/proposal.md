# Proposal: mobiel-inspectie

## Summary
Implement a Progressive Web App (PWA) for field inspectors to conduct inspections on location. Inspectors complete checklists, take photos, capture GPS coordinates, and add observations. V2 provides online PWA; V3 adds offline capability.

## Motivation
Mobile inspection is found in 16% of tenders (11/69) and is a critical differentiator for VTH tenders -- it is the primary tool for field inspectors at omgevingsdiensten.

## Affected Projects
- [x] Project: `procest` -- PWA manifest, inspection service, checklist engine, controller

## Scope

### In Scope
- PWA manifest and service worker registration
- InspectionService for managing inspection tasks and checklists
- ChecklistService for checklist completion with conformity tracking
- GPS location capture and validation
- Photo capture metadata (GPS, timestamp, checklist item link)
- InspectionController with API endpoints
- Responsive mobile-first UI considerations

### Out of Scope
- Offline capability (V3)
- Photo annotation tool (V3)
- Digital field signatures (V3)
- Report PDF generation (depends on Docudesk)
