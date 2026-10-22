# example-set-dashboard-data Delta

**Status**: proposed
**Scope**: dossiq
**OpenSpec changes**:

- [example-set-dashboard-data](../../)

## Purpose

The work dashboards have something to draw on the Zuiddrecht example set.

## ADDED Requirements

### Requirement: Pieter has a case whose term ends today

The Zuiddrecht set SHALL give Pieter's open Woo request Parkeervergunningen binnenstad a running statutory term whose
current end date is the install day.

#### Scenario: Vandaag eerst

- **GIVEN** the set installed today
- **THEN** My work's First today names Parkeervergunningen binnenstad

### Requirement: Pieter covers for a colleague

The set SHALL hold an active substitution in which Pieter covers for Esra Demir for all her work, running from before
the install day to after it.

#### Scenario: Aan mij toegewezen

- **GIVEN** the set installed today
- **THEN** "Including work you cover" adds Esra's open cases to Pieter's list

### Requirement: The inbox holds unrouted messages

The set SHALL hold seventeen intake log entries with outcome `inbox`, each from someone in the cast. Six SHALL name an
open case of the set by its number; the other eleven SHALL name no case number.

#### Scenario: Postvak

- **GIVEN** the set installed today
- **THEN** the inbox lists seventeen messages, six under "Looks like a case" and eleven under "No case"

### Requirement: Pieter has worked today

The set SHALL record that Pieter opened two of his own open cases on the install day and SHALL hold a note of his, written
on the install day, on a third.

#### Scenario: Dag afsluiten

- **GIVEN** the set installed today
- **THEN** Close out your day lists Bezwaar parkeerboete Havenstraat and Kapvergunning essen Parkweg as still open and
  Parkeervergunningen binnenstad as recorded today
