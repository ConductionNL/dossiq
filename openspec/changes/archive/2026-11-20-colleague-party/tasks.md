# Tasks: colleague-party

- [x] 1.1 Test (red first): `tests/Unit/Settings/ColleaguePartyTest.php`.
- [x] 1.2 Colleague schema, case partyKinds with identity shapes, version bumps, digests, translations.
- [x] 1.3 A surface: the case parties card's "Add party" (board DqZaakBetrokkenen "Partij toevoegen") searches residents, organisations and colleagues (`tests/vitest/colleaguePartySurface.spec.js`, SchemaHasSurfaceTest).
- [x] 1.4 DQ-DATA: seed Lars de Koning (and the adviser on 710d44a4) as colleagues linked to the case. One colleague: Lars is the adviser on the Lindelaan Woo case (710d44a4), and "Collega" is the word the Partijen card prints for his kind. Seeded where that case is written, portaliq's Zuiddrecht fragment (`lib/Settings/example-sets/zuiddrecht.json`): the colleague `collega-lars`, his role record in role type Adviseur on `woo-fietspad`, and the party link `partij-lars-adviseur`.
