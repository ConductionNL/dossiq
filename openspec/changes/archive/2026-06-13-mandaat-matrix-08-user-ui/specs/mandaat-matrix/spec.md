# mandaat-matrix Specification — Member 08: User Bevoegdheden UI

---
status: proposed
---

## Purpose

Let a zaakbehandelaar view their authority for the current case, filtered by their role(s), with
role-holder detail and a "What can I do?" filter.

## ADDED Requirements

### Requirement: User-Facing Bevoegdheden View

The case detail page SHALL provide a bevoegdheden view showing the mandate matrix for the case's
zaaktype filtered by the user's current role(s).

#### Scenario: Bevoegdheden matrix filtered by role

- GIVEN a zaakbehandelaar with role "Vergunningverlener" opens a zaak of type "Omgevingsvergunning"
- WHEN they open the bevoegdheden panel
- THEN the panel SHALL show only the mandaten their role(s) hold for this zaaktype, with columns
  Mandaat, Omschrijving, Plafond, Subdelegatie, current validity, and validity period
- AND a "What can I do?" filter SHALL show only decision types the user can unilaterally execute

### Requirement: Mandate Detail and Role Holders

The bevoegdheden view SHALL expand a mandate row to show its detail, current role holders, any
waarnemer relationship, and the mandateringsbesluit source.

#### Scenario: Row detail shows role holders and source

- GIVEN the bevoegdheden view is open
- WHEN the user clicks a mandate row
- THEN the panel SHALL expand to show the mandate description, wettelijke grondslag link, current
  role holders (people in the role today), and the MandateringsBesluit source reference
- AND if the user is acting as a waarnemer, the panel SHALL note they are substituting for the
  primary role holder
