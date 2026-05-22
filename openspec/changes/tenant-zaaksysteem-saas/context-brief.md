status: proposed

# Tenant Zaaksysteem SaaS

## Placement & Information Architecture

**Placement type:** `SETTING` — Setting under the app's Beheer/Admin/Configuration surface. Lives in the existing settings UI; no top-level menu entry.

**Lives at:** Configuratie › Admin › Tenant

**Rationale:** Multi-tenant SaaS settings.  
_Source: /tmp/ia-procest-hrmq.md_

> **Implementation note for builders:** Respect the placement above. Do not promote this spec to a top-level menu item, sub-page, or new route unless the placement type explicitly says so. If the placement is `DETAIL_TAB`, `WIDGET`, `ACTION`, `SETTING`, or `INFRA`, the feature must NOT introduce a new entry in the app sidebar. When in doubt, ask before creating a new top-level surface.

## Purpose

Kleine en middelgrote gemeenten, gemeenschappelijke regelingen, samenwerkingsverbanden en ZBO's hebben behoefte aan een volwaardig zaaksysteem zonder de kosten en complexiteit van een eigen on-premise installatie. Tegelijk eisen ze strikte data-isolatie: hun dossiers, mandaatregistraties en persoonsgegevens mogen onder geen beding zichtbaar of vermengbaar zijn met die van andere afnemers. De huidige Procest-installatie ondersteunt één organisatie per deployment, wat voor cloud-aanbieders en gemeentelijke samenwerkingsverbanden onpraktisch is: zij willen 50, 100 of 500 tenants op één codebase draaien met centrale upgrades en monitoring.

De `tenant-zaaksysteem-saas` uitbreiding maakt Procest multi-tenant capable: één deployment bedient meerdere klant-organisaties, elk met eigen zaaktypes, mandaat-matrix, theming, gebruikers, integraties en quota's. Tenant-data is fysiek gesegregeerd via per-tenant database-schemas (PostgreSQL `SET search_path`) of, optioneel, per-tenant database-instances voor klanten met hoogste compliance-eisen (e.g. BIO-baseline + ENSIA).

De uitbreiding levert ook een **tenant onboarding workflow**: een nieuwe gemeente die zich aanmeldt doorloopt een wizard (registratie → contractondertekening via Decidesk → mandaat-matrix import → zaaktype-templates kiezen → eHerkenning/DigiD koppelen → branding instellen) en is binnen 30 minuten productief. Per-tenant theming gebruikt CSS-variabelen op basis van de tenant's huisstijl-gids; per-tenant quotas (zaken/maand, opslag-GB, actieve gebruikers) worden in real-time gehandhaafd; billing-hooks emitteren events naar Shillinq voor factuurgeneratie (per-zaak of per-actieve-gebruiker model).

Voor SaaS-aanbieders (commerciële hosters, of grote gemeenten die hun systeem aan buurgemeenten verhuren als shared service) is dit het verschil tussen "we kunnen zaaksysteem-as-a-service aanbieden" en "we moeten per klant een aparte cluster opspinnen".

## Data Model

**Tenant**: `id`, `slug` (URL-safe identifier), `displayName`, `legalName`, `kvkNumber`, `contractRef` (naar Decidesk), `status` (onboarding/active/suspended/terminated), `tier` (basic/standard/enterprise), `createdAt`, `activatedAt`, `terminatedAt`, `dataResidency` (nl/eu), `isolationMode` (schema/database).

**TenantConfiguration**: `tenantRef`, `branding` (logo, primaryColor, secondaryColor, fontFamily, customCSS), `domain` (custom domain: gemeente-xyz.zaaksysteem.nl), `locale`, `timezone`, `dateFormat`, `currency`, `features[]` (enabled feature flags).

**TenantQuota**: `tenantRef`, `quotaType` (cases_per_month/storage_gb/active_users/api_calls_per_hour), `limit`, `currentUsage`, `resetAt`, `softLimitWarningPercent`, `enforcement` (warn/throttle/block).

**TenantUser**: `tenantRef`, `userRef`, `role`, `joinedAt`, `lastActiveAt`, `mfaEnabled`, `eherkenningLevel`.

**TenantMandate**: `tenantRef`, `mandateMatrixRef`, `effectiveFrom`, `effectiveTo`, `signedBy`, `documentRef`.

**TenantBillingEvent**: `id`, `tenantRef`, `eventType` (case_created/user_activated/storage_increment/api_burst), `quantity`, `unitPrice`, `currency`, `occurredAt`, `invoiceRef` (nullable, gevuld na facturatie via Shillinq).

**TenantOnboardingTask**: `id`, `tenantRef`, `step` (contract/mandate_import/sso_setup/branding/zaaktype_selection/go_live), `status` (pending/in_progress/completed/skipped), `completedBy`, `completedAt`, `blockedReason`.

## Requirements

### REQ-001: Tenant Provisioning bij Aanmelding

GIVEN een nieuwe gemeente meldt zich aan via het self-service portaal
WHEN de provisioning-workflow start na contractondertekening
THEN creëert het systeem een nieuw Tenant-record met unieke `slug` en `isolationMode`
AND provisioneert het een per-tenant database-schema (of database-instance bij `isolationMode = database`)
AND seedt het standaard zaaktype-templates, mandaat-matrix-template en standaard-rollen
AND configureert het een custom subdomain (`{slug}.zaaksysteem.nl`) met SSL-certificaat via Let's Encrypt
AND verstuurt het een welkomst-email met inlog-instructies naar de aangewezen tenant-admin

### REQ-002: Volledige Data-isolatie tussen Tenants

GIVEN gebruikers van Tenant A en Tenant B doen gelijktijdig API-calls
WHEN een query wordt uitgevoerd op de zaak-tabel
THEN garandeert het systeem dat gebruikers van Tenant A nooit zaken van Tenant B kunnen zien, zelfs niet bij een gemanipuleerde of foutieve filter-parameter
AND afdwingt het tenant-context op database-niveau (Row Level Security policies of search_path)
AND logt het elke cross-tenant-toegangspoging als beveiligingsincident
AND faalt het hard (HTTP 403) bij een mismatched tenant-context in JWT versus request

### REQ-003: Per-Tenant Zaaktypes en Mandaat-Matrix

GIVEN Tenant A is een kleine plattelandsgemeente met 8 zaaktypes
AND Tenant B is een 100k-inwoners-stad met 240 zaaktypes
WHEN een tenant-admin het zaaktype-overzicht opent
THEN toont het systeem uitsluitend zaaktypes van de eigen tenant
AND staat het toe om vrij zaaktypes toe te voegen, te wijzigen of te deactiveren binnen de tenant-scope
AND erft het optioneel zaaktypes uit het standaard-template (met "fork on first edit" copy-on-write semantiek)

### REQ-004: Tenant Onboarding Workflow

GIVEN een nieuwe tenant heeft het contract ondertekend
WHEN de tenant-admin voor het eerst inlogt
THEN toont het systeem een checklist met onboarding-stappen (mandaat-import, SSO-setup, branding, zaaktype-keuze, eerste medewerker uitnodigen, go-live)
AND begeleidt het elke stap met inline-help en voorbeelden
AND blokkeert het de "go-live"-stap totdat minimaal 1 zaaktype + 1 mandaat + 1 medewerker geconfigureerd zijn
AND markeert het de tenant-status als `active` na go-live-bevestiging

### REQ-005: Per-Tenant Theming en Branding

GIVEN Tenant A wil eigen huisstijl (oranje primary, eigen logo, eigen lettertype)
WHEN een tenant-admin in de branding-instellingen logo en kleuren upload/kiest
THEN slaat het systeem de TenantConfiguration op met logo-URL en CSS-variabelen
AND injecteert het bij elke page-render de tenant-specifieke `<style>`-tag met `--tenant-primary`, `--tenant-secondary`, `--tenant-font`
AND respecteert het NL Design System token-namen zodat NLdesign-componenten meeschakelen
AND ondersteunt het optioneel custom-CSS voor enterprise-tier (met sanitisation tegen XSS)

### REQ-006: Resource Quotas met Real-Time Handhaving

GIVEN Tenant A heeft een quota van 500 zaken/maand op het basic-tier
WHEN de tenant 500 zaken heeft aangemaakt en probeert zaak nummer 501 te creëren
THEN blokkeert het systeem de aanmaak met een duidelijke melding ("Quota bereikt — upgrade naar standard-tier")
AND emitteert het een TenantBillingEvent voor de overschrijdingspoging
AND verstuurt het bij 80% quota-gebruik een waarschuwing naar de tenant-admin
AND ondersteunt het tier-upgrade in real-time (binnen 1 minuut beschikbaar na upgrade-bevestiging)

### REQ-007: Per-Tenant SSO en Authenticatie

GIVEN Tenant A wil eHerkenning niveau 3 voor zijn medewerkers
AND Tenant B wil eigen Azure AD (gemeente-AD)
WHEN een gebruiker probeert in te loggen op `tenanta.zaaksysteem.nl`
THEN routeert het systeem naar de tenant-specifieke identity-provider zoals geconfigureerd
AND valideert het de SAML/OIDC-claims tegen de tenant-mandaat-matrix
AND koppelt het de gebruiker aan TenantUser met de juiste rol
AND staat het niet toe dat een eHerkenning-token van Tenant A wordt gebruikt voor Tenant B

### REQ-008: Billing-Hooks naar Shillinq

GIVEN een tenant heeft het pay-per-case-model gekozen
WHEN een zaak met succes wordt afgesloten
THEN emitteert het systeem een TenantBillingEvent (`event_type = case_closed`, `quantity = 1`, `unit_price = €4,50`)
AND aggregeert een dagelijkse job alle events tot een maandfactuur
AND verstuurt deze naar Shillinq voor PDF-generatie en email-verzending
AND toont de tenant-admin een real-time billing-dashboard met huidige maandstand

## Standards

- **PostgreSQL Row Level Security** voor query-niveau tenant-scoping
- **JWT met tenant-claim** (custom claim `tenant_id`) voor request-routing
- **OAuth 2.0 / OpenID Connect** voor SSO per tenant
- **SAML 2.0** voor eHerkenning- en gemeentelijke AD-integratie
- **eHerkenning niveau 2+/3/4** afhankelijk van zaaktype-classificatie
- **NL Design System** tokens voor theming-foundation
- **Common Ground laag 1 (data)** met tenant-segregatie als baseline-invariant
- **BIO 2.0** baseline voor enterprise-tier (extra logging, hard-isolation, pen-test elk kwartaal)
- **ISO 27001** voor SaaS-leverancier
- **AVG artikel 28** (verwerkersovereenkomst per tenant, machine-readable in tenant-metadata)
- **Let's Encrypt ACME** voor automatische SSL per tenant-subdomain

## Cross-app Dependencies

- **OpenRegister (multi-tenant)**: kern-vereiste — OpenRegister moet tenant-scope ondersteunen op alle schemas (zie OpenRegister roadmap ADR-XXX)
- **OpenConnector**: per-tenant connector-configuratie en credentials-vault-segregatie
- **Decidesk**: contract-ondertekening tijdens onboarding, mandaat-besluitvorming per tenant
- **Shillinq**: facturatie op basis van TenantBillingEvents
- **NLDesign**: theming-tokens als basis voor per-tenant CSS-variabelen
- **MyDash**: per-tenant BI-dashboards met strikte tenant-filter op alle queries
- **Pipelinq**: workflow-templates die per tenant kunnen worden geforkt

## Target Users

- **SaaS-aanbieders** die zaaksysteem-as-a-service aan gemeenten leveren (commercieel of als gemeenschappelijke regeling)
- **Gemeentelijke shared-service-organisaties** (e.g. samenwerking 8 buurgemeenten op één installatie)
- **Provincies / koepelorganisaties** die hun deelnemers een platform aanbieden
- **Hosting-partners** van het Conduction-ecosysteem die volledige services willen leveren
- **Kleine gemeenten** als eindafnemer (zonder eigen IT-afdeling) die instappen op een gehoste installatie
