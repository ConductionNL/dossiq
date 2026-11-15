## ADDED Requirements

### Requirement: The simple People tab lists the case's access links

The simple profile's People tab SHALL end with a Toegang card that lists the case's access links from the link list the Sharing tab reads. Each row SHALL show the holder's initials in a 32px circle, "Link for {label}", what the holder may do, and a pill for a revoked, expired or paused link or the date a live link expires. A live or paused link SHALL end in an icon button that revokes it. The card's Share button SHALL open the sidebar Sharing tab.

#### Scenario: a live link with an end date

- GIVEN a case with a read-only link for "Sanne de Vries" that expires on 15 November
- WHEN a handler the case is assigned to opens the Betrokkenen tab
- THEN the Toegang card shows "Link voor Sanne de Vries" with SV at 32px, "Alleen lezen, zonder account · de link kan eindigen", the pill "Verloopt 15 nov" and the button "Link intrekken"

#### Scenario: a reader the case is not assigned to

- GIVEN a colleague the case is not assigned to
- THEN the Toegang card says only the people the case is assigned to can see its links

#### Scenario: Share

- WHEN the handler clicks "Delen"
- THEN the case's sidebar opens on the Delen tab
