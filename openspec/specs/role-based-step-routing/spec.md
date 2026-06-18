---
status: done
---

## Purpose

@e2e exclude Role-based step routing is V1; role-filtered task generation is backend logic covered by PHPUnit.

> Enforcement mechanism: see `migrate-role-routing-to-or-rbac` — step/transition
> access is enforced on the OpenRegister RBAC group model using `roleType.ncGroupId`
> as the canonical NC group identifier. Roles are resolved to literal group ids at
> workflow-publish time and frozen onto each transition's `authorization` list; the
> requirements below (observable routing behaviour) are unchanged.

## Requirements

### Requirement: Role-Based Step Visibility

The system SHALL filter workflow steps and their resulting tasks based on the user's role on the case. Steps configured with an `assigneeRole` SHALL only appear in the task list of users who hold that role on the case.

**Feature tier**: V1

#### Scenario: Step restricted to specific role

- **WHEN** step "Inhoudelijke beoordeling" is configured with `assigneeRole: "Vergunningverlener"`
- **AND** user "jan" has role "Behandelaar" on case "ZK-2024-001"
- **AND** user "piet" has role "Vergunningverlener" on case "ZK-2024-001"
- **THEN** the task for "Inhoudelijke beoordeling" SHALL appear in piet's task list
- **AND** the task SHALL NOT appear in jan's task list

#### Scenario: Step with no role restriction

- **WHEN** step "Checklist invullen" has no `assigneeRole` configured
- **THEN** the resulting task SHALL appear in the task list of ALL users who have any role on the case

### Requirement: Role-Based Transition Access

The system SHALL restrict status transitions to users who hold one of the allowed roles. Transitions with `allowedRoles` defined SHALL only be visible and executable by users who hold at least one of those roles on the case.

**Feature tier**: V1

#### Scenario: Transition restricted to manager role

- **WHEN** transition "Goedkeuren" has `allowedRoles: ["Afdelingshoofd"]`
- **AND** the current user has role "Behandelaar" on the case
- **THEN** the "Goedkeuren" button SHALL NOT be displayed

#### Scenario: Transition with multiple allowed roles

- **WHEN** transition "Terugsturen" has `allowedRoles: ["Behandelaar", "Afdelingshoofd"]`
- **AND** the current user has role "Behandelaar"
- **THEN** the "Terugsturen" button SHALL be displayed and functional

#### Scenario: Transition with no role restriction

- **WHEN** transition "Annuleren" has no `allowedRoles` configured
- **THEN** the transition SHALL be available to any user who has any role on the case

### Requirement: Workflow Inheritance for Role Configuration

The system SHALL support workflow template inheritance where child zaaktypen inherit the parent's workflow and can override specific step role assignments.

**Feature tier**: Enterprise

#### Scenario: Child zaaktype inherits parent workflow

- **WHEN** zaaktype "Reguliere vergunning" extends parent "Omgevingsvergunning"
- **AND** the parent has a workflow with 5 steps
- **THEN** the child SHALL inherit all 5 steps with their role assignments

#### Scenario: Child overrides step role

- **WHEN** the child zaaktype overrides step "Inhoudelijke beoordeling" to use role "Senior Vergunningverlener" instead of "Vergunningverlener"
- **THEN** only the child's override SHALL apply for cases of the child type
- **AND** the parent's original role assignment SHALL remain unchanged
