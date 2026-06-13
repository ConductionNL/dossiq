status: proposed

# KCC Klantcontact Integratie

## Purpose

Het KlantContactCentrum (KCC) van een gemeente is de eerste lijn voor alle inkomende vragen: telefoon, email, web-formulier, chat, social media. KCC-medewerkers moeten in seconden bepalen wat de vraag is, wie de beller is, welke lopende zaken hij/zij heeft, en hoe de vraag te routeren. Vandaag werken ze met een lappendeken van losse systemen: telefooncentrale (CTI), Outlook, Topdesk of een ticketsysteem, het zaaksysteem, het BRP-bevraging-portaal, en handgeschreven post-its. Het resultaat: gemiddeld 4,2 minuten per call (vs. industrie-benchmark 2,8 minuut), 23% van calls eindigt in "ik bel u terug", en de zaak-historie van klantcontactmomenten is fragmentarisch.

De `kcc-klantcontact-integratie` Procest-uitbreiding consolideert klantcontact in één werkplek: bij een inkomende call ziet de KCC-medewerker direct (CTI-popup) wie belt (matching op telefoonnummer tegen BRP/Handelsregister), welke lopende zaken die persoon heeft, eerdere contactmomenten, en routing-suggesties. Elk contactmoment wordt gestructureerd vastgelegd als ContactMoment-record en gekoppeld aan een zaak (bestaand of nieuw). **Routing rules** categoriseren automatisch op basis van trefwoorden en gespreksonderwerp ("paspoort" → Burgerzaken, "kapotte lantaarnpaal" → Openbare Werken, "WMO" → Sociaal Domein) en suggereren de juiste behandelaar op basis van beschikbaarheid, expertise en werklast.

De uitbreiding ondersteunt **omnichannel**: dezelfde routing-engine verwerkt email (IMAP/Exchange/Microsoft Graph), web-formulieren (Procest formulier-engine), en chat (via OpenConnector op MS Teams of WhatsApp Business). Status-feedback aan de KCC-medewerker is realtime: zodra een behandelaar de zaak oppakt of afhandelt, krijgt de oorspronkelijke KCC-medewerker een notificatie zodat hij/zij de klant terugkoppeling kan geven. Volume-rapportage genereert wekelijks dashboards over kanaal-volume, gemiddelde afhandeltijd per kanaal, top-10-vraagcategorieën, en pieken in werklast — input voor capaciteitsplanning.

## Data Model

**ContactMoment**: `id`, `customerRef` (BRP-BSN of KvK-nummer, of anoniem), `customerName`, `customerPhone`, `customerEmail`, `channel` (phone/email/web_form/chat/social/in_person/letter), `direction` (inbound/outbound), `startedAt`, `endedAt`, `durationSeconds`, `subject`, `summary`, `transcript`, `outcome` (resolved/transferred/callback_scheduled/escalated), `caseRef` (gekoppelde zaak), `kccAgentRef`, `tags[]`, `sentimentScore`.

**RoutingRule**: `id`, `name`, `priority`, `matchConditions[]` (channel, keywords, regex, customer_type, time_of_day), `assignedDomain` (burgerzaken/openbare_werken/wmo/etc.), `assignedTeam`, `escalationPath`, `enabled`, `createdBy`, `lastModifiedAt`.

**KCCAgent**: `userRef`, `availableForChannels[]`, `currentStatus` (available/busy/break/after_call_wrap/offline), `skills[]` (e.g. Frans, gebarentaal-via-tolkcontact, WMO-expert), `workload` (currentActiveContacts, dailyContactCount).

**ContactQueue**: `id`, `channel`, `queueName`, `currentDepth`, `averageWaitSeconds`, `slaTargetSeconds`, `slaBreaches`, `staffedAgents`.

**CallbackRequest**: `id`, `contactMomentRef`, `customerPhone`, `requestedTimeWindow`, `preferredAgent`, `reason`, `scheduledFor`, `status` (scheduled/attempted/completed/failed), `attemptCount`.

**ChannelVolumeMetric**: `period`, `channel`, `inboundCount`, `outboundCount`, `avgHandleTimeSeconds`, `firstContactResolutionPct`, `customerSatisfactionScore`, `agentOccupancyPct`.

## Requirements

### REQ-001: CTI Popup bij Inkomende Call

GIVEN een burger belt het KCC-nummer
WHEN de call doorkomt bij een beschikbare KCC-medewerker
THEN toont het systeem binnen 1 seconde een popup met: caller-ID, BRP-match-resultaat (naam, adres, geboortedatum) of "onbekend", lopende zaken van deze persoon (max 5 meest recente), laatste 3 contactmomenten
AND opent het bij beantwoorden automatisch een nieuw ContactMoment-record met `channel = phone, direction = inbound`
AND start het opname-functionaliteit met expliciete consent-vraag aan de beller

### REQ-002: Belplan Ophalen en Tonen

GIVEN een KCC-medewerker wil weten of de gemeente vandaag een speciale belregeling heeft (e.g. extra openstelling voor verkiezingen)
WHEN de medewerker het belplan-paneel opent
THEN haalt het systeem het actuele belplan op uit Procest (configureerbaar door teamleider)
AND toont het: openingstijden, speciale onderwerpen vandaag, escalatie-instructies, no-go-onderwerpen (wat NIET behandelen aan de telefoon — bv. klachten over wethouder doorverwijzen)
AND ververst het belplan bij wijziging real-time zonder page-reload

### REQ-003: Automatische Routing op Trefwoorden

GIVEN een ContactMoment wordt geannoteerd met onderwerp "kapotte lantaarnpaal Hoofdstraat 24"
WHEN de KCC-medewerker op "Zaak aanmaken" klikt
THEN evalueert het systeem de RoutingRules op trefwoord-match (`lantaarnpaal` → Openbare Werken, team Beheer Openbare Ruimte)
AND suggereert het automatisch zaaktype "Melding Openbare Ruimte" en behandelend team
AND staat het de medewerker toe deze suggestie te accepteren (default) of handmatig te overrulen
AND logt het de routing-beslissing voor latere ML-feedback ("welke routing-suggesties worden vaak gewijzigd?")

### REQ-004: Behandelaar-Suggestie op Beschikbaarheid en Expertise

GIVEN een ContactMoment is gerouteerd naar team Openbare Werken
WHEN het systeem een specifieke behandelaar moet voorstellen
THEN selecteert het de behandelaar met:
  - status = available
  - laagste workload (aantal openstaande zaken)
  - relevante expertise (skill-tag matcht zaaktype)
  - laatst-contact met deze burger (continuity)
AND toont het de medewerker een top-3 met deze sortering plus motivatie ("Jan van Dijk: 12 open zaken, OBR-expert, eerder contact gehad")
AND staat het handmatige override toe

### REQ-005: Status-Feedback aan KCC-Medewerker

GIVEN een KCC-medewerker heeft een zaak doorgezet naar behandelaar Jan
WHEN Jan de zaak oppakt of een eerste actie uitvoert
THEN ontvangt de KCC-medewerker een notificatie in zijn werkplek ("Jan heeft zaak XZ-2026-0123 opgepakt")
AND bij afhandeling: notificatie met afhandel-samenvatting zodat de KCC-medewerker pro-actief kan terugbellen indien afgesproken
AND koppelt het systeem deze terugkoppeling als outbound ContactMoment aan de oorspronkelijke

### REQ-006: Omnichannel Email-Verwerking

GIVEN een burger stuurt een email naar info@gemeente.nl
WHEN de mail binnenkomt via IMAP/Microsoft Graph
THEN creëert het systeem automatisch een ContactMoment (`channel = email, direction = inbound`)
AND probeert het de afzender te matchen tegen BRP/Handelsregister via emailadres-koppeling
AND past het dezelfde RoutingRules toe als bij telefoon (keyword-extractie uit subject + body)
AND wijst het toe aan de juiste email-queue (Burgerzaken-mail / OBR-mail / etc.)
AND toont het in de KCC-werkplek-inbox met SLA-countdown (e.g. "antwoord binnen 2 werkdagen")

### REQ-007: Callback Scheduling

GIVEN een KCC-medewerker kan een vraag niet direct beantwoorden
WHEN hij/zij op "Terugbel-afspraak" klikt
THEN creëert het systeem een CallbackRequest met voorkeurs-tijdsblok (door beller opgegeven)
AND planent het de callback in de agenda van de behandelaar of medewerker zelf
AND notificeert het 15 minuten voor de afspraak de aangewezen agent
AND retry't het bij geen-gehoor met escalatie naar collega of email-fallback

### REQ-008: Volume-Rapportage Dashboard

GIVEN een teamleider wil de KCC-prestaties van vorige week beoordelen
WHEN hij/zij het volume-dashboard opent
THEN toont het systeem: totaal contacten per kanaal, gemiddelde afhandeltijd per kanaal, first-contact-resolution-percentage, top-10 vraagcategorieën, piek-uren, agent-occupancy, SLA-breaches
AND staat het filtering toe op periode, team, kanaal, agent
AND exporteert het naar Excel/PDF voor bestuurlijke rapportage
AND voorspelt het op basis van trend de werklast voor komende week (capaciteitsplanning)

## Standards

- **CTI (Computer Telephony Integration)** via TAPI of REST-API van moderne cloud-telefonie (e.g. Anywhere365, Genesys, Telecom1)
- **WebRTC** voor browser-based softphone-fallback
- **Microsoft Graph API** voor email-integratie met Exchange Online
- **IMAP IDLE** voor near-realtime polling bij niet-Exchange mailservers
- **JMAP** als modern alternatief voor IMAP
- **WCAG 2.1 AA** voor KCC-werkplek (toegankelijkheid voor agenten met beperkingen)
- **NEN 7510** voor logging van zorg-gerelateerde contactmomenten
- **AVG artikel 6 + 30** voor grondslagregistratie en verwerkingsregister van contactmomenten
- **ETSI EN 301 549** voor toegankelijkheid van real-time communication
- **Common Ground laag 5 (interactie)** als architectuurprincipe
- **KCM (Klantcontact Management) Common Ground-standaard** in ontwikkeling bij VNG Realisatie
- **NL Design System** voor agent-werkplek UI-componenten

## Cross-app Dependencies

- **Pipelinq kcc-werkplek**: kern-frontend waarop deze uitbreiding bouwt; deelt agent-state en queue-management
- **OpenRegister**: storage van ContactMoment, RoutingRule, KCCAgent, CallbackRequest
- **OpenConnector**: integratie met CTI-systemen, email-servers (Microsoft Graph, IMAP), chat-platforms (Teams, WhatsApp Business)
- **Procest**: zaak-creatie vanuit contactmoment, statusupdates terug naar KCC
- **OpenCatalogi**: KvK/BRP-bevraging voor caller-identification
- **MyDash**: KCC-volume-rapportage als dashboard-widgets
- **NLDesign**: agent-werkplek UI
- **Docudesk**: brief-/email-templates voor outbound communicatie

## Target Users

- **KCC-medewerkers** (frontline-agents) bij gemeenten en uitvoeringsorganisaties
- **KCC-teamleiders** voor monitoring, planning, kwaliteit
- **Backoffice-behandelaars** die door KCC worden gerouteerd
- **Klantcontact-managers** voor strategisch sturing en rapportage
- **Burgerzaken-medewerkers, Sociaal-domein-consulenten, OBR-medewerkers** als bestemming van gerouteerde contactmomenten
- **Bestuurders / griffies** als afnemer van volume-rapportages
