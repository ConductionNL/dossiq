# case-share-via-shares-leaf Specification

## Purpose
TBD - created by archiving change migrate-public-share-to-shares-leaf. Update Purpose after archive.
## Requirements
### Requirement: Public Case Sharing Is Created Through The OR Shares Leaf

Procest SHALL create, list, and revoke public share links for a case through OpenRegister's
`shares` integration leaf (ADR-019). Procest SHALL NOT generate share tokens of its own after this
migration.

@e2e exclude Token mint / revoke / public resolution are owned by OpenRegister's `shares` integration leaf (cross-app, ADR-019): mint runs through OR's `SharesProvider::create({type:'public-token'})` / `CaseTokenService::mint`, revoke through the leaf `delete(token:)`, and anonymous resolution through OR's `#[PublicPage]` `GET /api/public/case-tokens/{token}` (RBAC-respecting). None is a procest-only browser UI surface drivable without the OR shares leaf installed; the procest side is the in-process consumer wiring (verified by PHPUnit + code review). Mirrors the case-map-via-maps-leaf / inspection-forms-via-forms-leaf precedent.

#### Scenario: Creating a share link uses the shares leaf

- **GIVEN** a `case` object and the OR shares leaf enabled + whitelisted on the `case` schema
- **WHEN** a handler creates a public share link from the case detail page
- **THEN** the share link SHALL be created via the shares leaf, linked to the case
- **AND** the link SHALL appear in the shares leaf tab/widget
- **AND** no procest-minted share token SHALL be generated

#### Scenario: Revoking a share link uses the shares leaf

- **GIVEN** a case with an existing share link created via the leaf
- **WHEN** a handler revokes the link
- **THEN** the revocation SHALL be performed by the shares leaf
- **AND** subsequent public access via that link SHALL be denied

---

### Requirement: The Bespoke Public-Share Controller And Dialog Token Path Are Removed

Procest SHALL remove the in-app token-sharing path: the `PublicShareController` token-resolution
logic and the `CreateShareDialog.vue` "Share link" token path SHALL NOT remain after this
migration.

@e2e exclude This is a static codebase-removal check (absence of `PublicShareController.php`, the bespoke token routes, `validateToken`/`generateToken`/`getFilteredCaseData`, and the `CreateShareDialog.vue` token tab) verified by code review + PHPUnit + grep, not a procest-only browser UI surface. The replacement public resolution is OR's cross-app `#[PublicPage]` endpoint, which a procest-only e2e cannot mount without the OR shares leaf installed.

#### Scenario: In-app token path is gone

- **GIVEN** the procest codebase after this migration
- **WHEN** `lib/Controller/PublicShareController.php` and `src/views/cases/components/` are inspected
- **THEN** the bespoke share-token generation/resolution path SHALL NOT be present
- **AND** any remaining public route SHALL delegate to the shares leaf's public-access resolution

---

### Requirement: Partner-Organisation Handover Is Out Of Scope For The Shares Leaf

Procest SHALL treat the "Partner organization" share type as zaak-domain handover logic, not a
generic share, and SHALL NOT migrate it to the shares leaf unless it is confirmed to be a generic
share token.

@e2e exclude Verifying that the partner-organisation handover path stays in-app (and is NOT moved to the shares leaf) is a static scope/code-review check on `CaseSharingService::createPartnerShare` + the reduced `CreateShareDialog.vue`/`ShareTab.vue` partner-only surface, covered by PHPUnit + code review — not a distinct procest browser UI surface introduced by this migration.

#### Scenario: Partner handover is not forced onto the leaf

- **GIVEN** the "Partner organization" share type in the former dialog
- **WHEN** this migration is applied
- **THEN** only the public token-share path SHALL move to the shares leaf
- **AND** the partner-organisation handover path SHALL remain in-app if it is zaak-domain logic

