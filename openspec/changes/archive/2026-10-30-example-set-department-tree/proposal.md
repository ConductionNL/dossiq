# Proposal: example-set-department-tree

## Why

DqAfdelingen draws the Zuiddrecht departments as a tree:
- Gemeente Zuiddrecht at the top;
- under it Fysiek domein (with Vergunningen, Toezicht en handhaving and Buitenruimte), Sociaal domein (with Jeugdteam and Werk en inkomen), Bedrijfsvoering, and Informatie en Woo.

`r23/department-tree` nests the groups pane from the organisation roles (`organisatieRol`: `ncGroupId` and `parentRoleId`). The example set had no such roles, and the two shipped team roles named no group.

## What changes

- Six department groups in dossiq's fragment: Fysiek domein, Toezicht en handhaving, Buitenruimte, Jeugdteam, Werk en inkomen and Bedrijfsvoering. The others are cast groups.
- Eight `organisatieRol` records, each naming its group and its parent role:
  - Gemeente Zuiddrecht
  - Fysiek domein
  - Buitenruimte
  - Sociaal domein
  - Jeugdteam
  - Werk en inkomen
  - Bedrijfsvoering
  - Informatie en Woo
- Two changes on the shipped roles. Team Vergunningen gets `zuiddrecht-vergunningen` and Team Handhaving gets `zuiddrecht-toezicht-en-handhaving`, both under Fysiek domein.
- Needs `r23/department-tree` to show.
