---
id: intro
title: Introductie
sidebar_position: 1
description: Dossiq is het zaaksysteem voor Nextcloud. Het volgt de ZGW-API's en CMMN, draait flows per zaaktype en archiveert in de werkomgeving zelf.
---

# Documentatie van dossiq

Dossiq is het zaaksysteem voor Nextcloud. Je behandelt een zaak van aanvraag tot besluit tot archief, in de werkomgeving die je medewerkers al gebruiken.

We bouwden het samen met Nextcloud, voor de gemeente Baarn en de Open Webconcept-gemeenten.

## Begin hier

| Je wilt | Lees |
|---|---|
| Weten wat dossiq kan | [Wat dossiq kan](./capabilities.md) |
| Zien hoe het eruitziet | [Schermen](./screens.md) |
| Weten hoe het archiveert | [Archivering](./archiving.md) |
| Het installeren | [Installatie](/docs/installation) |
| Eén feature in detail lezen | [Featuredocumentatie](/docs/Features) |

![Het dashboard van dossiq](/img/screens/DqDashboard.webp)

## Wat dossiq anders maakt

- **Nederlandse en Europese standaarden.** Zaken volgen de ZGW-API's. Een zaaktype kan ook als CMMN-zaakplan lopen.
- **Flows per zaaktype.** Fasen, taken, regels en beslistabellen stel je in per zaaktype. Een nieuwe procedure is inrichting, geen maatwerk.
- **In Nextcloud.** Documenten zijn Nextcloud-bestanden, mail is Nextcloud Mail, rechten zijn Nextcloud-groepen. Dossiq past daarmee in een soevereine werkplek die op Nextcloud draait, zoals Mijn Bureau.
- **Gebouwd op Nextcloud Tables.** We bouwen dossiq op Nextcloud Tables. Samen met Nextcloud brengen we Tables in lijn met het principe data bij de bron.
- **Archiefwaardig.** Bewaartermijn, vernietiging en overbrenging naar het e-Depot horen bij de zaak.

## Architectuur

- **Frontend**: een Vue.js-app binnen het Nextcloud-framework.
- **Backend**: PHP, als Nextcloud-app met controllers en services.
- **Datalaag**: OpenRegister, met getypeerde schema's, een audittrail en bewaartermijnen. OpenRegister biedt een tabel uit Nextcloud Tables aan als register, zonder hem te kopiëren.
- **API**: ZGW-compatibele REST-API via instelbare veldmapping.
- **AI**: Nextcloud ExApps, zoals Ollama en Presidio.
- **Thema**: tokens van het NL Design System.

Lees de [architectuur](/docs/Technical/architecture) voor de details.
