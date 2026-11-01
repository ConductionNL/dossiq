## ADDED Requirements

### Requirement: The Toegang card lists the teams and groups that can open the case

The Toegang card on the simple People tab SHALL list, after the case's access links, the
users and groups that hold access to the case, read from OpenRegister's grants list for the
case (`an-object-lists-its-grants-with-their-origin`). Each row SHALL show a group icon or
the user's initials at 32px, the display name, and a line saying where the access came from.
The service account SHALL NOT be shown. The card SHALL NOT decide who has access; it shows
what OpenRegister answers.

#### Scenario: a handling team through the case type
- GIVEN a case of a case type whose handling team is the group "Team Woo en informatie"
- WHEN the assignee opens the Betrokkenen tab
- THEN the Toegang card shows a row with a group icon, "Team Woo en informatie" and "via het zaaktype"

#### Scenario: a group a colleague shared the case with
- GIVEN Pieter Jansen shared the case with the group "Juridische zaken"
- WHEN the assignee opens the Betrokkenen tab
- THEN the Toegang card shows a row with a group icon, "Juridische zaken" and "gedeeld door Pieter Jansen"

#### Scenario: a share on the main case
- GIVEN a sub-case whose main case Pieter Jansen shared with Tom Visser
- WHEN the assignee of the sub-case opens its Betrokkenen tab
- THEN the Toegang card shows a row with TV at 32px, "Tom Visser" and "via de hoofdzaak, gedeeld door Pieter Jansen"

#### Scenario: English
- GIVEN the user's language is English
- THEN the rows read "via the case type" and "shared by Pieter Jansen"

#### Scenario: a colleague the case is not assigned to
- GIVEN a member of the handling team the case is not assigned to
- WHEN they open the Betrokkenen tab
- THEN the grant rows are replaced by "Alleen de mensen aan wie deze zaak is toegewezen zien wie toegang heeft"
- AND the link rows above keep their own answer

#### Scenario: the grants cannot be read
- GIVEN OpenRegister answers 500 for the case's grants
- THEN the grant rows show "Kon niet lezen wie toegang heeft" and never an empty list
