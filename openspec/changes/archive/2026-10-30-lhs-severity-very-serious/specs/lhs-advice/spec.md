# lhs-advice Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [lhs-severity-very-serious](../../)

## Purpose

An LHS advice can sit in the fourth consequence column.

## ADDED Requirements

### Requirement: An LHS advice can be very serious

An LHS recommendation's severity SHALL offer gering, aanzienlijk, ernstig and zeer_ernstig, each with a label, and the
advice matrix SHALL label the fourth column "Gevolg D: zeer ernstig".

#### Scenario: DqHandhaving

- **WHEN** a matrix holds the zeer_ernstig column
- **THEN** the advice page draws it as "Gevolg D: zeer ernstig"
