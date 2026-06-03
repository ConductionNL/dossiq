# supplier-portal Specification — Member 04: Supplier Scope & API Security

---
status: proposed
---

## Purpose

Enforce supplier scoping on every data access and provide the auth/rate-limit/audit-logging
middleware stack. Consumes the schemas from member 01 and the session from member 02.

## ADDED Requirements

### Requirement: Supplier-Scoped Data Access

The system SHALL restrict every supplier-facing data query to the logged-in supplier's own
records and SHALL deny cross-supplier access.

#### Scenario: A supplier sees only its own cases

- GIVEN supplier A is logged in
- WHEN supplier A requests its cases
- THEN only supplier A's cases SHALL be returned
- AND a supplier with no cases SHALL receive an empty result set

#### Scenario: Cross-supplier access is forbidden

- GIVEN supplier B is logged in
- WHEN supplier B requests a case belonging to supplier A
- THEN the system SHALL return 403 Forbidden and disclose no data

### Requirement: API Security Middleware

The system SHALL reject unauthenticated requests, rate-limit by IP, and audit-log mutations with
masked PII.

#### Scenario: Unauthenticated and over-limit requests are rejected

- GIVEN a request with no valid session
- WHEN it reaches a protected endpoint
- THEN the system SHALL return 401 Unauthorized
- AND GIVEN more than 100 requests in one minute from a single IP THEN the system SHALL return 429

#### Scenario: Mutations are audit-logged with masked sensitive fields

- GIVEN a POST/PUT/DELETE on a sensitive resource (e.g. IBAN change)
- WHEN it is processed
- THEN an audit entry SHALL record user, timestamp, action, and old/new values
- AND IBAN SHALL be masked to the last 4 digits and email to the domain only in the log
