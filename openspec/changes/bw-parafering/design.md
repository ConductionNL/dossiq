# Design: bw-parafering

## Architecture

### Parafering Workflow
1. Steller creates voorstel from case context
2. System loads parafeerroute from case type configuration
3. For each step in the route, a task is created for the assigned actor
4. Actor performs action (paraferen/terugsturen/adviseren)
5. System advances to next step or returns to steller
6. All actions recorded in immutable audit trail
7. When all steps complete, voorstel is ready for bestuurlijke behandeling

### Data Model
- `voorstel` -- Linked to case, contains type, onderwerp, steller, status
- `parafeerRoute` -- Ordered list of steps per case type + voorstel type
- `parafeerStap` -- Step in route: actor(s), type (advisory/parafering/accordering), parallel flag
- `parafeerActie` -- Recorded action: actor, action type, timestamp, comments

### API Endpoints
- `POST /api/parafering/voorstellen` -- Create a voorstel
- `GET /api/parafering/voorstellen/{id}` -- Get voorstel with parafering status
- `POST /api/parafering/voorstellen/{id}/paraferen` -- Paraferen action
- `POST /api/parafering/voorstellen/{id}/terugsturen` -- Return with comments
- `POST /api/parafering/voorstellen/{id}/adviseren` -- Non-binding advice
- `GET /api/parafering/voorstellen/{id}/audit-trail` -- Full audit trail

## Dependencies
- Task management for creating parafering tasks
- NotificatieService for actor notifications
- Decision schema for recording besluiten
