# lhs-advice Specification

## Purpose
The LHS matrix names its rows and columns the way the advice refers to them.

## Requirements

### Requirement: The LHS matrix numbers its axes

The LHS advice matrix SHALL label behaviour rows with their number (1 to 4) and consequence columns with their letter
(A onward), followed by the word.

#### Scenario: DqHandhaving

- **WHEN** a handler opens an LHS advice
- **THEN** the rows read "Gedrag 1: welwillend" to "Gedrag 4: crimineel" and the columns "Gevolg A: gering",
  "Gevolg B: aanzienlijk", "Gevolg C: ernstig"

### Requirement: The LHS matrix card fits the matrix

The LHS advice page SHALL size the matrix card to its content, with no empty band above or below the matrix.

#### Scenario: DqHandhaving

- **WHEN** a handler opens an LHS advice
- **THEN** the matrix starts right under the card's subtitle and the card ends under its last row

### Requirement: The LHS matrix states the advice

The LHS advice page SHALL show, under the matrix, an info notice naming the recommended intervention in bold and the
matrix version, behaviour row and consequence column it came from.

#### Scenario: DqHandhaving

- **WHEN** a handler opens an advice of last onder dwangsom at gedrag 2 and gevolg C from matrix version 2
- **THEN** the notice reads "Advies: Last onder dwangsom" in bold and "Uitkomst van de beslistabel, versie 2, met gedrag
  2 en gevolg C."

### Requirement: An LHS advice can be very serious

An LHS recommendation's severity SHALL offer gering, aanzienlijk, ernstig and zeer_ernstig, each with a label, and the
advice matrix SHALL label the fourth column "Gevolg D: zeer ernstig".

#### Scenario: DqHandhaving

- **WHEN** a matrix holds the zeer_ernstig column
- **THEN** the advice page draws it as "Gevolg D: zeer ernstig"

### Requirement: The LHS matrix's label column and notice words follow the board

The LHS advice matrix SHALL give its row labels a 170px column, and its notice SHALL name the intervention in lower case
mid-sentence.

#### Scenario: DqHandhaving

- **WHEN** a handler opens an advice of last onder dwangsom
- **THEN** the notice reads "Advies: last onder dwangsom" and the cells start after a 170px label column
