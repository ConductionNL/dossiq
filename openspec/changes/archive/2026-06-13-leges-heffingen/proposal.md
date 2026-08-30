# Proposal: leges-heffingen

## Why

Nederlandse gemeenten heffen leges voor tientallen soorten diensten (paspoorten, rijbewijzen, omgevingsvergunningen, APV-vergunningen, etc.), vastgesteld in jaarlijkse legesverordeningen met hiërarchische tarieventabellen. Vandaag worden leges handmatig berekend via Excel of legacy-maakwerk, wat leidt tot fouten, inconsistentie, vertraging in facturering, en reconciliatie-problemen met het financiële systeem. Procest kent geen geautomatiseerde leges-berekening, waardoor burgerzaken- en financieel medewerkers veel handwerk hebben.

Deze change brengt een volwassen leges-berekeningsengine in Procest met:
1. Importeren van jaarlijkse legesverordeningen uit raadsbesluiten in decidesk
2. Automatische tariefberekening op zaak-aanmaak op basis van zaak-attributen
3. Variant-selectie en dynamische korting-toepassing
4. Automatische factuur-creatie in shillinq accounts-receivable
5. Restitutie-afhandeling bij ingetrokken aanvragen
6. Historisch correcte tarieven bij zaken die over jaarsgrenzen lopen
7. Audit-trail met grondslag, kortingen, en berekenings-motivering

## What Changes

1. **LegesTariefTabel** entiteit — versionable tarieventabellen per fiscaal jaar, importeerbaar uit decidesk raadsbesluiten
2. **LegesTarief** entiteit — individuele tariefregels met grondslag, BTW-percentage, grootboekrekening, productcode
3. **LegesVariant** entiteit — sub-tarieven (Tarief A/B, spoed-variant) geactiveerd op basis van zaak-attributen
4. **LegesKorting** entiteit — vrijstelling- en kortingsregels (65-plus, minima, herhaalaanvraag) met condities en wettelijke grondslag
5. **LegesBerekening** entiteit — concrete berekening per zaak met bedrag, BTW, toegepaste kortingen, factuurId
6. **LegesRestitutie** entiteit — restitutiebesluit met percentage/bedrag, creditfactuur-ref
7. **LegesCalculationService** — bepaalt juiste tarief, variant, kortingen; roept shillinq AR aan voor factuur
8. **LegesVerordingImportService** — parseert tarieventabel uit decidesk-raadsbesluit, creëert LegesTariefTabel-versie
9. Vue UI components voor tariefverordening-import, tariefbeheer, berekening-toelichting op zaak-detailpagina
10. Koppeling met shillinq AR voor factuur-creatie en creditfacturen bij restitutie

## Impact

- **Affected projects**: procest (primary), shillinq (AR factuurering), decidesk (verordening-bron), openregister (abac policy engine voor autorisatie), pipelinq (minima-check)
- **Code surface**: 2 service groups, 2 controllers, 4 entities, 3 Vue components, decidesk import script
- **APIs**: `POST /api/cases/{id}/leges/calculate`, `POST /api/leges/import-verordening`, `GET /api/leges/{caseId}`, `POST /api/leges/refund`, `GET /api/leges/audit-trail`
- **Dependencies**: OpenRegister, shillinq accounts-receivable, decidesk (raadsbesluiten), pipelinq (minima-verificatie), openconnector (BRP-koppeling)
- **Standards**: Gemeentewet art. 229 (leges-heffing), BTW-richtlijn, VNG Modelverordening leges, NEN 7510 (privacy inkomensdata), GEMMA productcatalogus
