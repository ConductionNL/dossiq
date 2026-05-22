status: proposed

# Mobiel Inspectie Offline

## Purpose

Veld-inspecteurs in het Vergunningen-, Toezicht- en Handhavingsdomein (VTH) en het sociaal domein werken vaak op locaties zonder betrouwbare netwerkverbinding: kelders, schuren, agrarische percelen, achterstandswijken met slechte 4G-dekking, of buitengebied waar netdekking simpelweg ontbreekt. Tegelijk eist de hedendaagse zaakgericht-werken praktijk dat elke inspectie direct wordt vastgelegd: foto's, GPS-coördinaten, checklist-resultaten, spraakmemo's, getuige-verklaringen. De huidige praktijk — papieren formulieren op klembord, later overtypen in het zaaksysteem — leidt tot dubbel werk, foutmarges van 15-20% en gemiddeld 4 dagen vertraging tussen veldbezoek en zaak-update.

De `mobiel-inspectie-offline` Procest-uitbreiding levert een Progressive Web App (PWA) die volledig offline functioneert: inspecteurs starten hun werkdag op kantoor, synchroniseren de dagplanning (zaken + checklisten + plattegronden + relevante historische documenten) naar lokale IndexedDB, en werken vervolgens uren in het veld zonder verbinding. Bij elke handeling (foto maken, checklist-item afvinken, spraakmemo opnemen) wordt een Pending-Operation gequeued in een Service Worker-cache. Zodra het apparaat weer online is, replayt de sync-engine de queue tegen de OpenRegister API, met conflictresolutie wanneer een collega in tussentijd dezelfde zaak heeft bewerkt.

Voor de gebruiker voelt het systeem als één ononderbroken applicatie — alleen een subtiele sync-indicator (groen/oranje/rood) toont de status. Voor de organisatie elimineert het de tussenstap "overtypen", waardoor inspectie-uitkomsten direct in de zaak landen en automatische vervolg-acties (handhavingsbeschikking, hercontrole-planning, ketenpartner-notificatie) zonder vertraging kunnen starten.

## Data Model

**FieldInspection** (extends Procest zaak): `id`, `caseRef`, `inspectorRef`, `scheduledAt`, `startedAt`, `completedAt`, `gpsLocation` (lat/lon/accuracy/timestamp), `status` (planned/in_progress/synced/conflict), `offlineCreatedAt`, `syncedAt`, `deviceId`.

**ChecklistResult**: `id`, `inspectionRef`, `checklistTemplateRef`, `items[]` (each: `questionId`, `answer`, `evidenceRefs[]`, `notes`, `answeredAt`, `gpsAtAnswer`).

**ChecklistTemplate**: `id`, `name`, `domain` (vth/sociaal/bouw/horeca), `version`, `items[]` (`questionId`, `text`, `type` (yes_no/scale/text/photo_required/measurement), `required`, `conditionalOn`, `helpText`).

**FieldEvidence**: `id`, `inspectionRef`, `type` (photo/voice_memo/document/sketch), `localBlobRef`, `cloudUrl` (nullable), `gpsLocation`, `capturedAt`, `transcription` (voor voice_memo), `transcriptionStatus`, `tags[]`, `sensitivityLevel`.

**SyncQueue**: `id`, `deviceId`, `operationType` (create/update/upload/delete), `targetEntity`, `targetId`, `payload`, `queuedAt`, `attemptCount`, `lastAttemptAt`, `lastError`, `status` (pending/syncing/synced/conflict/failed).

**ConflictRecord**: `id`, `syncQueueRef`, `serverVersion`, `clientVersion`, `conflictType` (concurrent_edit/deleted_remote/permission_lost), `resolution` (client_wins/server_wins/manual_merge), `resolvedBy`, `resolvedAt`.

## Requirements

### REQ-001: Offline Dagplanning Synchronisatie

GIVEN een inspecteur start de PWA op kantoor met netwerk
WHEN hij/zij op "Dag synchroniseren" tikt
THEN downloadt het systeem alle voor vandaag geplande inspecties (FieldInspection-records), bijbehorende checklist-templates, historische zaakdocumenten en kaartmateriaal (tegels rond het zaakadres tot zoom-level 18) naar lokale IndexedDB
AND toont het een progress-indicator met geschatte download-grootte (bv. "32 MB van 48 MB")
AND markeert het de planning als "ready_offline" met expirytime na 24 uur

### REQ-002: Offline Checklist Invullen

GIVEN een inspecteur is op locatie zonder netwerk
WHEN hij/zij een gepland inspectie opent en checklist-vragen beantwoordt
THEN slaat het systeem elk antwoord direct lokaal op (IndexedDB, atomic write)
AND queue't het een SyncQueue-operation per beantwoorde vraag
AND blijft de UI volledig responsief zonder netwerk-roundtrips
AND toont het een subtiele sync-badge ("12 wijzigingen wachten op sync")

### REQ-003: GPS Geotag bij Elke Handeling

GIVEN een inspecteur maakt een foto of beantwoordt een checklist-vraag
WHEN de handeling wordt vastgelegd
THEN voegt het systeem automatisch de huidige GPS-coördinaten (met accuracy in meters) en timestamp toe aan het record
AND waarschuwt het de gebruiker als GPS-accuracy slechter is dan 50m ("Locatie onnauwkeurig — wacht of voeg handmatig adres toe")
AND faalt het stil-fallback naar zaakadres als GPS volledig faalt, met een sensorless-flag

### REQ-004: Foto Capture met Compressie

GIVEN een inspecteur tikt op "Foto toevoegen" in een checklist-item
WHEN hij/zij een foto neemt via de camera-API
THEN comprimeert het systeem de foto client-side naar max 2MB (JPEG quality 80, max 1920px breedte)
AND voegt het EXIF-metadata toe (GPS, timestamp, inspectorId, caseRef, deviceId)
AND slaat het de blob lokaal op met referentie in FieldEvidence
AND queue't het een upload-operation naar het OpenRegister-bestandssysteem

### REQ-005: Spraakmemo Transcriptie

GIVEN een inspecteur neemt een spraakmemo op tijdens een inspectie
WHEN hij/zij de opname stopt
THEN slaat het systeem de audio (Opus-codec, max 5min) lokaal op
AND queue't het een transcriptie-operation die bij weer-online de audio naar de Procest-LLM-endpoint (qwen-3.5 of vergelijkbaar) stuurt
AND koppelt het de getranscribeerde tekst aan het FieldEvidence-record met `transcriptionStatus = synced`
AND houdt het de originele audio beschikbaar voor naluisteren

### REQ-006: Sync Queue Replay bij Weer-Online

GIVEN het apparaat heeft connectiviteit teruggekregen
WHEN het systeem netwerk detecteert (navigator.onLine + ping naar OR-endpoint)
THEN start het automatisch de SyncQueue-replay in volgorde van `queuedAt`
AND retry't het gefaalde operations met exponential backoff (1s, 5s, 30s, 5min, 30min)
AND toont het een sync-progress-bar met "Synchroniseren: 14/47"
AND markeert het succesvol verwerkte operations als `synced` en verwijdert ze na 7 dagen

### REQ-007: Conflict Resolution bij Gelijktijdige Bewerking

GIVEN een collega heeft op kantoor dezelfde zaak bewerkt terwijl de inspecteur offline was
WHEN de SyncQueue een 409-Conflict ontvangt op een update-operation
THEN creëert het systeem een ConflictRecord met server- en client-versies
AND toont het de inspecteur een merge-UI met side-by-side velden ("Server: 'afgekeurd' / Mijn invoer: 'goedgekeurd onder voorwaarden'")
AND laat het de inspecteur kiezen: behoud mijn versie / accepteer server / handmatig samenvoegen
AND logt het de resolutie in een audit-trail conform AVG (artikel 5 lid 1f)

### REQ-008: Offline Plattegronden en Kaartmateriaal

GIVEN een inspecteur navigeert naar een zaakadres in buitengebied
WHEN hij/zij de kaart opent in offline modus
THEN toont het systeem vooraf gedownloade kaarttegels (PDOK BRT-Achtergrondkaart) en kadastrale percelen
AND ondersteunt het tekenen van inspecteur-aantekeningen op de kaart (polygon, point, lijn)
AND slaat het deze sketches op als FieldEvidence met `type = sketch`

## Standards

- **PWA Manifest + Service Worker** (W3C) voor installeerbaarheid en offline-cache
- **IndexedDB** met Dexie.js voor lokale opslag (Workbox sync strategy)
- **Web Geolocation API** voor GPS, met fallback naar `requestPermission` flow
- **MediaRecorder API** (Opus codec) voor spraakmemo's
- **EXIF 2.32** voor foto-metadata
- **OGC Web Map Tile Service (WMTS)** voor PDOK-tegels
- **AVG / GDPR** artikel 5, 25, 32 voor privacy-by-design (lokale encryptie van blobs via Web Crypto API)
- **BAG / BGT** voor adres- en perceel-validatie
- **WCAG 2.1 AA** voor toegankelijkheid op mobiel touch-device
- **Common Ground Lagen-model** (data laag 1, services laag 4) voor zaak-koppeling
- **NEN 7510** voor logging van toegang tot zaakgegevens in het veld

## Cross-app Dependencies

- **OpenRegister**: zaak/inspectie-storage, file-attachment voor foto's/audio, conflict-detection via ETag/versioning
- **OpenConnector**: sync-queue replay route, retry-policy, dead-letter-queue voor permanent-gefaalde operations
- **Pipelinq**: workflow-trigger "inspectie afgerond" → vervolg-actie (handhavingsbeschikking-concept genereren)
- **NLDesign**: NL Design System tokens voor mobiel-eerst componenten (touch-targets ≥44px, hoog-contrast voor buitenwerk)
- **Docudesk**: PDF-generatie van inspectie-rapport bij sync-completion

## Target Users

- **VTH-inspecteurs** (gemeentes, omgevingsdiensten, provincies) — bouw-, milieu-, horeca-controles
- **Sociaal domein consulenten** — huisbezoeken, woonvoorzieningen-check, jeugdzorg-veiligheidstaxatie
- **Toezichthouders waterschappen** — keringen, lozingen, agrarische naleving
- **Boswachters / handhaving openbaar groen** — illegale dumping, kapvergunningen
- **Gemeentelijke BOA's** — APV-overtredingen, ondermijning-signalen
