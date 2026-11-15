# Tasks: social-domain-procedures-are-case-types

## 1. Generic (r25/social-domain-lookup-over-cases)
- [x] 1.1 case 1.49.0: subjectBsn, sensitiveProperties (dossiq-sensitive); propertyDefinition 1.7.0: sensitive; arrayKeyBy on the case form declaration
- [x] 1.2 Access test over OpenRegister's PropertyRbacHandler
- [x] 1.3 Cross-domain lookup answers from open cases, as the system; moved records answer through their case
- [x] 1.4 Record import: dedupeBy, references (caseType 1.36.0)

## 2. nextcloud-vue
- [x] 2.1 values.arrayKeyBy, on DQ-EDIT's stored-answer prefill (nc-vue fix/r25-answers-by-definition-flag)

## 3. Seeds
- [x] 3.1 Duplicates: the shipped seeds already carry unique slugs (the 12 Oct duplicates on :8080 are an earlier seeding, not the data); the import joins rows by case number (dedupeBy)

## 4. Participatiewet
- [x] 4.1 Package bijstandsaanvraag, record import over participatiewetZaak (participatiewetZaak 1.3.0), palette

## 4b. Guards on sensitive answers
- [x] 4b.1 RequiredFieldGuard checks sensitiveProperties as the system (SensitiveAnswerCheck), presence only
- [x] 4b.2 Bijstandsaanvraag: Toetsing afronden requires vermogenstoets and inkomenstoets

## 4c. Bijstandsaanvraag decision term (Ruben, 23 Oct)
- [x] 4c.1 td-bijstandsaanvraag (56 days, Awb 4:13 lid 2), carriesTerm true, term definitions seeded before the import

## 5. Wmo, Jeugdwet
- [x] 5.1 Wmo-melding package over wmoZaak, adopting the seeded case type by identifier; references indicatiestelling.caseId, toestemming.caseId, mdoOverleg.caseIds (list, caseType 1.37.0); wmoZaak 1.3.0
- [x] 5.2 Jeugdwet: the melding-jeugdwet package (1.5.0, install auto, category Jeugdhulp) imports jeugdwetZaak; references gezinsplan.caseId, toestemming.caseId, mdoOverleg.caseIds; jeugdwetZaak 1.3.0
- [x] 5.4 Jeugdwet flow (jeugdwet 1.6.0): through Evaluatie and Verlenging aangevraagd, guards on hulpvraag and gezinsplan
- [x] 5.3 Jeugdwet decision term (Ruben, 23 Oct): td-melding-jeugdwet, 56 days, Awb 4:13 lid 2, carriesTerm true, no extension
