# supplier-portal Specification — Member 02: eHerkenning Authentication

---
status: proposed
---

## Purpose

Authenticate suppliers via eHerkenning niveau 2+ with KvK validation, SupplierUser creation, and
session issuance. Consumes the `Supplier` and `SupplierUser` schemas from member 01.

## ADDED Requirements

### Requirement: eHerkenning Login and KvK Validation

The system SHALL authenticate suppliers through the OpenConnector eHerkenning broker, validate the
KvK claim against the `Supplier` register, and reject login when the supplier is unknown or
inactive.

#### Scenario: Login redirect carries the required scopes

- GIVEN a supplier visits the portal login page
- WHEN they click "Inloggen met eHerkenning"
- THEN the system SHALL redirect to the OpenConnector broker with `response_type=code`,
  `scope=openid profile kvk`, and the callback `redirect_uri`
- AND a temporary state token SHALL be set to prevent CSRF

#### Scenario: Valid KvK claim resolves to an active supplier

- GIVEN eHerkenning returns an authorization code
- WHEN the system exchanges it and extracts the `kvkNumber` claim
- THEN it SHALL look up the `Supplier` by `kvkNumber`
- AND if the Supplier exists with `status` = active it SHALL proceed to session issuance
- AND if the Supplier does not exist OR is inactive/blacklisted it SHALL display the appropriate
  Dutch error and create NO session

### Requirement: SupplierUser Linking and Session Issuance

The system SHALL create or link a `SupplierUser` for the authenticated individual and issue a
session token with a 2-hour TTL and a financial re-authentication flag.

#### Scenario: First login auto-creates a read_only SupplierUser

- GIVEN a valid KvK claim for an active Supplier with no matching SupplierUser
- WHEN the session is established
- THEN a `SupplierUser` SHALL be created with `role` = read_only, `eherkenningLevel` from the
  token, `addedBy` = system, and `status` = active
- AND a session token SHALL be issued with TTL = 2 hours and `requiresReAuthForFinancial` = true

#### Scenario: Session refreshes before expiry and rejects after expiry

- GIVEN an active session within 15 minutes of its 2-hour expiry
- WHEN the user performs an action
- THEN the session token SHALL be refreshed transparently and the refresh logged for audit
- AND GIVEN an expired session WHEN a protected route is accessed THEN the user SHALL be
  redirected to login with a session-expired message
