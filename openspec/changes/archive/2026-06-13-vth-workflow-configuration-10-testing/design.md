# Design: vth-workflow-configuration-10-testing

## Architecture

`kind: code` member (ADR-032). Pure test code (ADR-008) — no production behaviour changes. PHPUnit unit + integration tests, plus an end-to-end DSO test simulating OpenRegister events.

## Test Layout

- Unit: `LegesCalculationServiceTest`, `BeschikkingGenerationServiceTest`, `LhsoLookupServiceTest`, `DsoIntakeServiceTest`, `MobileInspectionServiceTest`.
- Integration: `WorkflowTransitionTest` exercising Omgevingsvergunning intake→beschikking→verleend, Toezichtzaak inspection, Handhavingszaak LHSO/intervention, with guard validation and notification assertions.
- E2E: `DsoIntegrationTest` simulating an ObjectCreatedEvent on a STAM 2.0 vergunningaanvraag, transitioning the case, and asserting VergunningStatusChangedEvent dispatch per transition.

## Security (ADR-005)

Tests assert per-object authorization behaviour where relevant (e.g. a case-scoped endpoint rejects access to a non-owned case). No mock-based bypasses of real authorization (tests assert real behaviour, not stubbed checks).
