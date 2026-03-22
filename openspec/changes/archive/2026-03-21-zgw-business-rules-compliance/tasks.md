# Tasks: ZGW Business Rules Compliance

## Task 1: Fix ZRC-007a Eindstatus Detection
- **spec_ref**: zgw-business-rules-delta.md#zrc-007-eindstatus-and-zaak-closing
- **files**: `ZgwZrcRulesService.php`
- **acceptance**: When statustype has highest volgnummer and isEindstatus is not set, zaak einddatum is populated

## Task 2: Fix ZRC-007b Gebruiksrecht on Close
- **spec_ref**: zgw-business-rules-delta.md#zrc-007b-gebruiksrecht-on-close
- **files**: `ZgwZrcRulesService.php`
- **acceptance**: All linked informatieobjecten get indicatieGebruiksrecht set when zaak closes

## Task 3: Fix ZRC-007q Gebruiksrecht Validation
- **spec_ref**: zgw-business-rules-delta.md#zrc-007q-gebruiksrecht-validation
- **files**: `ZgwZrcRulesService.php`
- **acceptance**: Eindstatus creation rejected when any linked informatieobject lacks indicatieGebruiksrecht

## Task 4: Fix ZRC-008c Heropenen Scope Check
- **spec_ref**: zgw-business-rules-delta.md#zrc-008c-heropenen-scope-check
- **files**: `ZgwZrcRulesService.php`
- **acceptance**: 403 returned when consumer lacks zaken.heropenen scope

## Task 5: Fix ZRC-010 Error Codes
- **spec_ref**: zgw-business-rules-delta.md#zrc-010-communicatiekanaal-validation
- **files**: `ZgwZrcRulesService.php`
- **acceptance**: Error code is `bad-url` for invalid communicatiekanaal URL

## Task 6: Fix ZRC-013a Error Codes
- **spec_ref**: zgw-business-rules-delta.md#zrc-013a-hoofdzaak-not-found
- **files**: `ZgwZrcRulesService.php`
- **acceptance**: Error code is `does-not-exist` for non-existent hoofdzaak

## Task 7: Fix ZRC-015 ProductenOfDiensten Validation
- **spec_ref**: zgw-business-rules-delta.md#zrc-015-productenofdiensten-validation
- **files**: `ZgwZrcRulesService.php`
- **acceptance**: Zaak creation rejected when productenOfDiensten not subset of zaaktype

## Task 8: Fix ZRC-016/018/019/020 Cross-Type Validation
- **spec_ref**: zgw-business-rules-delta.md#zrc-016018019020-cross-type-validation
- **files**: `ZgwZrcRulesService.php`
- **acceptance**: Sub-resource creation rejected when type does not belong to zaak's zaaktype

## Task 9: Fix ZRC-021 Archiefactiedatum Derivation
- **spec_ref**: zgw-business-rules-delta.md#zrc-021-archiefactiedatum-derivation
- **files**: `ZgwZrcRulesService.php`
- **acceptance**: archiefactiedatum correctly derived from resultaattype brondatumArchiefprocedure

## Task 10: Fix ZRC-002 Identification Uniqueness
- **spec_ref**: zgw-business-rules-delta.md#zrc-002-identification-uniqueness
- **files**: `ZgwZrcRulesService.php`
- **acceptance**: Duplicate identificatie + bronorganisatie combination rejected

## Task 11: Fix ZRC-005b/023h Cascade Delete
- **spec_ref**: zgw-business-rules-delta.md#zrc-005b023h-cascade-delete
- **files**: `ZgwZrcRulesService.php`, `ZgwService.php`
- **acceptance**: ObjectInformatieObject deleted when ZaakInformatieObject or zaak deleted

## Task 12: Fix ZRC-009 Vertrouwelijkheidaanduiding Default
- **spec_ref**: zgw-business-rules-delta.md#zrc-009-vertrouwelijkheidaanduiding-default
- **files**: `ZgwZrcRulesService.php`
- **acceptance**: Default derived from zaaktype without template leakage

## Task 13: Fix ZRC-006 Authorization Filtering
- **spec_ref**: zgw-business-rules-delta.md#zrc-006-authorization-filtering
- **files**: `ZrcController.php`, `ZgwZrcRulesService.php`
- **acceptance**: Zaken list filtered by consumer's authorized zaaktypen and vertrouwelijkheidaanduiding

## Task 14: Optimize Endpoint Performance
- **spec_ref**: zgw-business-rules-delta.md#endpoint-response-time
- **files**: `ZgwService.php`, `ZgwZrcRulesService.php`, `ZgwZtcRulesService.php`, `ZgwDrcRulesService.php`, `ZgwBrcRulesService.php`
- **acceptance**: Average endpoint response time under 200ms

## Task 15: Newman Test Suite Validation
- **spec_ref**: zgw-business-rules-delta.md (all sections)
- **files**: Newman test collection
- **acceptance**: 353/353 assertions passing, 0 failures
