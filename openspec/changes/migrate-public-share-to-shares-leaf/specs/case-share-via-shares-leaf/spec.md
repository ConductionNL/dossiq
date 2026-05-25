# case-share-via-shares-leaf Specification

## ADDED Requirements

### Requirement: Public Case Sharing Is Created Through The OR Shares Leaf

Procest SHALL create, list, and revoke public share links for a case through OpenRegister's
`shares` integration leaf (ADR-019). Procest SHALL NOT generate share tokens of its own after this
migration.

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

#### Scenario: Partner handover is not forced onto the leaf

- **GIVEN** the "Partner organization" share type in the former dialog
- **WHEN** this migration is applied
- **THEN** only the public token-share path SHALL move to the shares leaf
- **AND** the partner-organisation handover path SHALL remain in-app if it is zaak-domain logic
