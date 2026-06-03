# Design: mobiel-inspectie

## Architecture

### PWA Setup
- `manifest.json` in app root for PWA installation
- Service worker for basic caching (V2: cache-first for static assets)
- Standalone display mode, portrait orientation

### Inspection Flow
1. Inspector opens app, sees today's inspection task list
2. Inspector taps an inspection to open it
3. System captures GPS coordinates, warns if >500m from planned location
4. Inspector works through checklist items (Conform/Niet-conform/N.v.t.)
5. Non-conformity triggers mandatory photo requirement
6. Photos captured via device camera with GPS + timestamp metadata
7. Inspector completes all items and taps "Inspectie afronden"
8. System generates report and updates case status

### Data Model
- `inspection` -- Linked to case, inspector, planned date/time, address, GPS coordinates
- `checklist` -- Template of items per case type
- `checklistItem` -- Individual item: description, conformity status, notes, photo refs
- `inspectionPhoto` -- Photo metadata: file ref, GPS, timestamp, checklist item link

### API Endpoints
- `GET /api/inspections` -- List assigned inspections (filterable by date)
- `GET /api/inspections/{id}` -- Get inspection with checklist
- `POST /api/inspections/{id}/checklist/{itemId}` -- Complete a checklist item
- `POST /api/inspections/{id}/photos` -- Upload inspection photo
- `POST /api/inspections/{id}/location` -- Record GPS coordinates
- `POST /api/inspections/{id}/complete` -- Mark inspection as complete

## Dependencies
- Nextcloud Files for photo storage
- Task management for inspection task assignment
- Geolocation API (browser)
- MediaStream API (camera)
