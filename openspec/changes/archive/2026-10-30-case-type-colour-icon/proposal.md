# Proposal: case-type-colour-icon

## Why

The boards draw each case type in its own colour and icon: the type pill on a case (DqZaak and 14 more boards draw
Woo-verzoek red), the type tag in the team queue, and the menu (DqZaak: Woo-verzoeken with a document, Bezwaarschriften
with a gavel, Klachten with a speech bubble). A case type carried neither, so every type pill was neutral (COMPDIFF r19:
the largest part of 79 pill backgrounds on 40 boards). Ruben decided on 20 October 2026 to add both.

## What changes

- `caseType.colour`: optional, the NL Design System hue list `statusType.colour` uses. That list gains `teal` and
  `teal-light`, the boards' tone for permits, subsidies and tenders, so both stay one list.
- `caseType.icon`: optional, a name from the app's registered icon set (`src/icons.js`).
- Every shipped case type names both (table below). caseType moves to 1.26.0, statusType to 1.7.3, the register to
  0.20.59, and each changed shipped row's version moves so an upgrade refreshes it.
- The case type form and card offer both: the colour as a select with a swatch per hue, the icon as a select that shows
  each icon.
- The type pill on a case and the case type tags in the lists take the colour and icon (nextcloud-vue
  `resolve.colorField` / `iconField`); a pinned case type's menu entry shows its icon.

## Colours and icons

| Case type | Colour | Icon | Source |
|---|---|---|---|
| Woo-verzoek, Freedom of Information Request | red | FileDocumentOutline | boards (Woo red on 24 pills; menu icon) |
| Bezwaar | purple | Gavel | boards |
| Klacht behandeling, Citizen Complaint | orange | MessageAlertOutline | icon from the boards' menu; colour chosen |
| Melding openbare ruimte | orange | MapMarkerOutline | colour from DqTeamwachtrij; icon chosen |
| Omgevingsvergunning (and its deelzaak demo), Building Permit | teal | OfficeBuildingOutline | colour from boards; icon chosen |
| Bouwtoezicht | teal | AccountHardHat | chosen |
| Milieuadvies | green | MapOutline | chosen |
| Subsidieaanvraag, Innovatiefonds 2026, Cultuursubsidie 2026, Grant application | teal | CashMultiple | colour from DqSubsidie; icon chosen |
| Evenementenvergunning (both versions) | blue | Calendar | chosen |
| Parkeervergunning bewoners, Kenteken wijzigen parkeervergunning | blue | CardAccountDetailsOutline | chosen |
| Leverancier (four supplier case types) | grey | HandshakeOutline | chosen |
| AVG-verzoek (demo) | purple | ShieldAccountOutline | chosen |

Board conflicts, decided by majority: DqWerkbord draws Woo-verzoek blue on two pills and one board draws it neutral
(24 red); one board draws Omgevingsvergunning, Bezwaar and Melding openbare ruimte as a white filter chip, which is a
chip, not a type tone.

## Scope

A case type without a colour or icon draws as before. Case types an organisation made keep none until an author picks
them. The kanban card's type pill and the case type catalogue cards do not read the fields yet.
