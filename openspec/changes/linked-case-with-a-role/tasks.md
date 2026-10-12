# Tasks: linked-case-with-a-role

- [x] 1.1 `caseType.relations[]` in the register, validated by `RoleLinkDefinition`
- [x] 1.2 `RoleLinkService`: link with a declared role, typed relation plus link record, actor from the session, idempotent
- [x] 1.3 `GET /api/cases/{caseId}/links` and `POST /api/cases/{caseId}/links/{role}` behind `CaseAccessGuard`
- [x] 1.4 Marker rules from `link_marker_rules`, applied by `LinkMarkerListener` on create and update
- [x] 1.5 Outcome cascades from `link_cascades`, `POST /api/links/cascades/{name}/{targetId}`
- [x] 1.6 Unit tests on a neutral fixture case type
- [ ] 2.1 Bezwaar package declares the roles, markers and cascades (waits on R1's installer)
- [ ] 2.2 Move `BezwaarCreationHook`, `BezwaarObjectionController`, `BeroepEscalationListener`, `Bezwaar\BeroepService`, `DwangsomBezwaarService` onto this, deleting each class and its unit tests once the package journey passes
