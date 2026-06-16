# supplier-portal Specification — Member 16: Cross-Cutting Tests, A11y/Security Audit & Docs

---
status: proposed
---

## Purpose

Verify the end-to-end portal with E2E journeys and a formal accessibility + security audit, and
document it for deployment. Spans the behaviour shipped by all prior members.

## ADDED Requirements

### Requirement: End-to-End Supplier Journeys Are Verified

The system SHALL pass automated end-to-end tests covering the principal supplier journeys.

#### Scenario: Core journeys pass end-to-end

- GIVEN the full portal is deployed to a test environment
- WHEN the E2E suite runs
- THEN the login→dashboard→tender→download→logout journey SHALL pass
- AND the admin-invite→activate→role-scoped-tabs journey SHALL pass
- AND the invoice→message→response and contract-renewal-request journeys SHALL pass

### Requirement: Accessibility and Security Audit Pass

The system SHALL pass a WCAG 2.1 AA audit and a security audit before release.

#### Scenario: Audits gate the release

- GIVEN the portal is feature-complete
- WHEN the accessibility audit runs
- THEN keyboard navigation, ARIA labelling, and contrast ≥4.5:1 SHALL pass
- AND the security audit SHALL confirm no cross-supplier data leakage, masked PII in logs, enforced
  financial re-auth, rate limiting, and CSRF protection on all mutations

### Requirement: Portal Documentation Is Published

The system SHALL ship API, deployment, and user documentation plus a release/rollback plan.

#### Scenario: Documentation set is complete

- GIVEN the portal is ready for release
- WHEN the documentation is reviewed
- THEN an API reference, deployment guide, user guide, and a release + rollback plan SHALL exist
