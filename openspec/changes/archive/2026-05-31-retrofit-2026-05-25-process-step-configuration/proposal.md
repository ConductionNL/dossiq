# Retrofit — process-step-configuration

Adds 1 new REQ describing the `StepConfigValidator` static validator that is the canonical pre-publish gate for every workflowTemplate step's `config` block.

The existing spec covers the CRUD shape of process steps and runtime step-to-task mapping; this REQ documents the SLA / required-fields / auto-actions / escalation-rule validation pipeline that runs at workflow publish time.

## Affected code units
- lib/Service/StepConfigValidator.php (1 public static method + 5 private helpers) — `validate()` is the only public surface; helpers handle SLA, required-fields, auto-actions, escalation-rule sub-validation

Source: openspec/coverage-report.md generated 2026-05-24. Tracks ConductionNL/procest#565.
