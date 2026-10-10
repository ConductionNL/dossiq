## 1. Board

- [ ] 1.1 Draw the beschikking chain on `DqZaakBesluiten`: every beschikking in issue order with its
  number, "Geldt" or "Vervangen door B-2026-000124", and the actions "Wijzigingsbeschikking
  maken" and "Intrekkingsbeschikking maken" on a signed one. (UI waits for board DqZaakBesluiten
  chain, decision 162)

## 2. Case page

- [ ] 2.1 Render the chain on the case page as the board shows it, reading the case's
  beschikkingen (`caseId`, `supersedes`, `supersededBy` are facetable). (UI waits for board
  DqZaakBesluiten chain, decision 162)
- [ ] 2.2 Wire the two actions to `POST /api/beschikkingen/{id}/successor` and show a refusal's
  sentence as it comes back. (UI waits for board DqZaakBesluiten chain, decision 162)
- [ ] 2.3 e2e: a case with a beschikking and two corrections lists all three in issue order with
  in force / replaced. (live pass, decision 139)
