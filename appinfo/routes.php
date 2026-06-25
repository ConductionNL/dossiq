<?php

/**
 * Procest Route Configuration
 *
 * Defines all HTTP routes for the Procest application.
 *
 * @category Routes
 * @package  OCA\Procest
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

// The mechanical boilerplate routes — dashboard#page (`/`), the SPA catch-all
// (`/{path}`), settings#index/create/load, preferences#getPreference/setPreference,
// metrics#index and health#index — are now provided by the OpenRegister AppHost
// canonical route table (ADR-040). URLs, verbs and route names are unchanged.
// The procest-bespoke dashboard PWA assets (dashboard#serviceWorker /
// dashboard#webManifest) and every domain route below are passed through as
// `$extra`; they are inserted before the catch-all so they keep priority.
return \OCA\OpenRegister\AppHost\Routes::standard([
        // AI-Assisted Processing (specific endpoints precede wildcard routes).
        ['name' => 'ai#classify',        'url' => '/api/ai/classify',        'verb' => 'POST'],
        ['name' => 'ai#extract',         'url' => '/api/ai/extract',         'verb' => 'POST'],
        ['name' => 'ai#ask',             'url' => '/api/ai/ask',             'verb' => 'POST'],
        ['name' => 'ai#summarize',       'url' => '/api/ai/summarize',       'verb' => 'POST'],
        ['name' => 'ai#suggestRouting',  'url' => '/api/ai/suggest-routing', 'verb' => 'POST'],
        ['name' => 'ai#suggestNext',     'url' => '/api/ai/suggest-next',    'verb' => 'POST'],
        // First-time setup wizard (ADR-042)
        ['name' => 'setup#status',       'url' => '/api/setup/status',              'verb' => 'GET'],
        ['name' => 'setup#saveConfig',   'url' => '/api/setup/config',              'verb' => 'POST'],
        ['name' => 'setup#runAction',    'url' => '/api/setup/action/{actionId}',   'verb' => 'POST'],
        ['name' => 'ai#recordAction',    'url' => '/api/ai/record-action',   'verb' => 'POST'],
        ['name' => 'ai#auditIndex',      'url' => '/api/ai/audit',           'verb' => 'GET'],
        ['name' => 'ai#getSettings',     'url' => '/api/ai/settings',        'verb' => 'GET'],
        ['name' => 'ai#updateSettings',  'url' => '/api/ai/settings',        'verb' => 'POST'],
        ['name' => 'ai#healthCheck',     'url' => '/api/ai/health',          'verb' => 'POST'],

        // Parafering Actions (must precede any wildcard routes).
        ['name' => 'parafeerActie#create', 'url' => '/api/parafeer-actie', 'verb' => 'POST'],
        ['name' => 'parafeerActie#index',  'url' => '/api/parafeer-actie', 'verb' => 'GET'],

        // KCC Klantcontact (kcc-klantcontact-integratie).
        // Static/verb routes precede the {id} wildcard routes.
        ['name' => 'kccRouting#evaluate', 'url' => '/api/kcc/routing/evaluate', 'verb' => 'POST'],
        ['name' => 'kccRouting#index',    'url' => '/api/kcc/routing-rules', 'verb' => 'GET'],
        ['name' => 'kccRouting#create',   'url' => '/api/kcc/routing-rules', 'verb' => 'POST'],
        ['name' => 'kccRouting#update',   'url' => '/api/kcc/routing-rules/{id}', 'verb' => 'PUT'],
        ['name' => 'kccRouting#destroy',  'url' => '/api/kcc/routing-rules/{id}', 'verb' => 'DELETE'],

        ['name' => 'kccContact#indexCallbacks',  'url' => '/api/kcc/callback-requests', 'verb' => 'GET'],
        ['name' => 'kccContact#scheduleCallback', 'url' => '/api/kcc/callback-requests', 'verb' => 'POST'],
        ['name' => 'kccContact#cancelCallback',  'url' => '/api/kcc/callback-requests/{id}/cancel', 'verb' => 'POST'],

        ['name' => 'kccContact#index',   'url' => '/api/kcc/contact-moments', 'verb' => 'GET'],
        ['name' => 'kccContact#create',  'url' => '/api/kcc/contact-moments', 'verb' => 'POST'],
        ['name' => 'kccContact#related', 'url' => '/api/kcc/contact-moments/{id}/related', 'verb' => 'GET'],
        ['name' => 'kccContact#show',    'url' => '/api/kcc/contact-moments/{id}', 'verb' => 'GET'],
        ['name' => 'kccContact#update',  'url' => '/api/kcc/contact-moments/{id}', 'verb' => 'PUT'],

        // Subsidieverlening-keten (subsidieverlening-keten spec) — AWB titel 4.2.
        // Static/verb routes precede the {id} wildcard routes; the public
        // subsidieregister feed precedes the authenticated /api/subsidies list.
        ['name' => 'subsidieRegister#export', 'url' => '/api/subsidies/register/export', 'verb' => 'GET'],
        ['name' => 'subsidie#index',  'url' => '/api/subsidies', 'verb' => 'GET'],
        ['name' => 'subsidie#create', 'url' => '/api/subsidies', 'verb' => 'POST'],
        ['name' => 'subsidie#approveTussenrapportage', 'url' => '/api/subsidies/tussenrapportages/{reportId}/beoordelen', 'verb' => 'POST'],
        ['name' => 'subsidie#finalizeVaststelling', 'url' => '/api/subsidies/vaststellingen/{vaststellingId}/vast', 'verb' => 'POST'],
        ['name' => 'subsidie#signBeschikking', 'url' => '/api/subsidies/beschikkingen/{beschikkingId}/sign', 'verb' => 'POST'],
        ['name' => 'subsidie#publishBeschikking', 'url' => '/api/subsidies/beschikkingen/{beschikkingId}/publish', 'verb' => 'POST'],
        ['name' => 'subsidie#transition', 'url' => '/api/subsidies/{id}/transition', 'verb' => 'POST'],
        ['name' => 'subsidie#createBeschikking', 'url' => '/api/subsidies/{id}/beschikking', 'verb' => 'POST'],

        // Zaakportaal "Mijn gemeente" citizen portal (zaakportaal-mijngemeente).
        // Static/verb routes precede the {id} wildcard routes.
        ['name' => 'zaakportaal#cases',                     'url' => '/api/portaal/cases', 'verb' => 'GET'],
        ['name' => 'zaakportaal#messages',                  'url' => '/api/portaal/messages', 'verb' => 'GET'],
        ['name' => 'zaakportaal#sendMessage',               'url' => '/api/portaal/messages', 'verb' => 'POST'],
        ['name' => 'zaakportaal#objectionDeadline',         'url' => '/api/portaal/objections/validate-deadline', 'verb' => 'POST'],
        ['name' => 'zaakportaal#submitObjection',           'url' => '/api/portaal/objections', 'verb' => 'POST'],
        ['name' => 'zaakportaal#submitComplaint',           'url' => '/api/portaal/complaints', 'verb' => 'POST'],
        ['name' => 'zaakportaal#requests',                  'url' => '/api/portaal/requests', 'verb' => 'GET'],
        ['name' => 'zaakportaal#getPreferences',            'url' => '/api/portaal/notification-preferences', 'verb' => 'GET'],
        ['name' => 'zaakportaal#updatePreferences',         'url' => '/api/portaal/notification-preferences', 'verb' => 'PATCH'],
        ['name' => 'zaakportaal#caseDetail',                'url' => '/api/portaal/cases/{id}', 'verb' => 'GET'],

        // Leverancier-zaakportaal — operator-side read endpoints for the
        // Vue surface shipped in chain members 06/08/10/11/14/15.
        // Supplier-facing eHerkenning + JWT flow is exposed by a separate
        // edge entry-point (chain member 02 — deferred).
        ['name' => 'supplierPortal#dashboard',     'url' => '/api/leverancier-portaal/dashboard',         'verb' => 'GET'],
        ['name' => 'supplierPortal#tenders',       'url' => '/api/leverancier-portaal/tenders',           'verb' => 'GET'],
        ['name' => 'supplierPortal#tenderDetail',  'url' => '/api/leverancier-portaal/tenders/{id}',      'verb' => 'GET'],
        ['name' => 'supplierPortal#invoices',      'url' => '/api/leverancier-portaal/invoices',          'verb' => 'GET'],
        ['name' => 'supplierPortal#contracts',     'url' => '/api/leverancier-portaal/contracts',         'verb' => 'GET'],
        ['name' => 'supplierPortal#kpi',           'url' => '/api/leverancier-portaal/kpi',               'verb' => 'GET'],
        ['name' => 'supplierPortal#messages',      'url' => '/api/leverancier-portaal/messages',          'verb' => 'GET'],
        ['name' => 'supplierPortal#sendMessage',   'url' => '/api/leverancier-portaal/messages',          'verb' => 'POST'],

        // Leverancier-zaakportaal — contract list/detail + renewal request (chain member 09).
        ['name' => 'contract#index',          'url' => '/api/leverancier-portaal/contracts/list',                'verb' => 'GET'],
        ['name' => 'contract#requestRenewal', 'url' => '/api/leverancier-portaal/contracts/{id}/request-renewal', 'verb' => 'POST'],
        ['name' => 'contract#show',           'url' => '/api/leverancier-portaal/contracts/{id}',                'verb' => 'GET'],

        // Leverancier-zaakportaal — operator-side write endpoints (master-data
        // mutations, chain member 12). Address + contactPerson apply
        // immediately; IBAN + accreditation are 4-eyes via Procest cases.
        ['name' => 'supplierProfile#updateAddress',      'url' => '/api/leverancier-portaal/profile/address',        'verb' => 'POST'],
        ['name' => 'supplierProfile#updateContact',      'url' => '/api/leverancier-portaal/profile/contact',        'verb' => 'POST'],
        ['name' => 'supplierProfile#requestIbanChange',  'url' => '/api/leverancier-portaal/profile/iban',           'verb' => 'POST'],
        ['name' => 'supplierProfile#submitAccreditation','url' => '/api/leverancier-portaal/profile/accreditations', 'verb' => 'POST'],

        // ZGW Mapping Management.
        ['name' => 'zgwMapping#index', 'url' => '/api/zgw-mappings', 'verb' => 'GET'],
        ['name' => 'zgwMapping#show', 'url' => '/api/zgw-mappings/{resourceKey}', 'verb' => 'GET'],
        ['name' => 'zgwMapping#update', 'url' => '/api/zgw-mappings/{resourceKey}', 'verb' => 'PUT'],
        ['name' => 'zgwMapping#destroy', 'url' => '/api/zgw-mappings/{resourceKey}', 'verb' => 'DELETE'],
        ['name' => 'zgwMapping#reset', 'url' => '/api/zgw-mappings/{resourceKey}/reset', 'verb' => 'POST'],

        // Case Definition Portability (export/import zaaktype packages).
        ['name' => 'caseDefinition#export', 'url' => '/api/case-definitions/export', 'verb' => 'POST'],
        ['name' => 'caseDefinition#validate', 'url' => '/api/case-definitions/validate', 'verb' => 'POST'],
        ['name' => 'caseDefinition#import', 'url' => '/api/case-definitions/import', 'verb' => 'POST'],

        // ── DRC (Documenten) ────────────────────────────────────────────
        // Special endpoints (must precede wildcard routes).
        ['name' => 'drc#download', 'url' => '/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download', 'verb' => 'GET'],
        ['name' => 'drc#lock', 'url' => '/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/lock', 'verb' => 'POST'],
        ['name' => 'drc#unlock', 'url' => '/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/unlock', 'verb' => 'POST'],
        // Bestandsdelen (chunked upload).
        ['name' => 'drc#uploadChunk', 'url' => '/api/zgw/documenten/v1/bestandsdelen/{uuid}', 'verb' => 'PUT'],
        // Audit trail.
        ['name' => 'drc#audittrailIndex', 'url' => '/api/zgw/documenten/v1/{resource}/{uuid}/audittrail', 'verb' => 'GET'],
        ['name' => 'drc#audittrailShow', 'url' => '/api/zgw/documenten/v1/{resource}/{uuid}/audittrail/{auditUuid}', 'verb' => 'GET'],
        // CRUD.
        ['name' => 'drc#index', 'url' => '/api/zgw/documenten/v1/{resource}', 'verb' => 'GET'],
        ['name' => 'drc#create', 'url' => '/api/zgw/documenten/v1/{resource}', 'verb' => 'POST'],
        ['name' => 'drc#show', 'url' => '/api/zgw/documenten/v1/{resource}/{uuid}', 'verb' => 'GET'],
        ['name' => 'drc#update', 'url' => '/api/zgw/documenten/v1/{resource}/{uuid}', 'verb' => 'PUT'],
        ['name' => 'drc#patch', 'url' => '/api/zgw/documenten/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
        ['name' => 'drc#destroy', 'url' => '/api/zgw/documenten/v1/{resource}/{uuid}', 'verb' => 'DELETE'],

        // ── ZRC (Zaken) ─────────────────────────────────────────────────
        // Nested sub-resource routes (must precede wildcard routes).
        ['name' => 'zrc#zaakeigenschappenIndex', 'url' => '/api/zgw/zaken/v1/zaken/{zaakUuid}/zaakeigenschappen', 'verb' => 'GET'],
        ['name' => 'zrc#zaakeigenschappenCreate', 'url' => '/api/zgw/zaken/v1/zaken/{zaakUuid}/zaakeigenschappen', 'verb' => 'POST'],
        ['name' => 'zrc#zaakeigenschappenShow', 'url' => '/api/zgw/zaken/v1/zaken/{zaakUuid}/zaakeigenschappen/{uuid}', 'verb' => 'GET'],
        ['name' => 'zrc#zaakeigenschappenUpdate', 'url' => '/api/zgw/zaken/v1/zaken/{zaakUuid}/zaakeigenschappen/{uuid}', 'verb' => 'PUT'],
        ['name' => 'zrc#zaakeigenschappenPatch', 'url' => '/api/zgw/zaken/v1/zaken/{zaakUuid}/zaakeigenschappen/{uuid}', 'verb' => 'PATCH'],
        ['name' => 'zrc#zaakeigenschappenDestroy', 'url' => '/api/zgw/zaken/v1/zaken/{zaakUuid}/zaakeigenschappen/{uuid}', 'verb' => 'DELETE'],
        // Zaakbesluiten sub-resource.
        ['name' => 'zrc#zaakbesluitenIndex', 'url' => '/api/zgw/zaken/v1/zaken/{zaakUuid}/besluiten', 'verb' => 'GET'],
        // Zoek endpoint.
        ['name' => 'zrc#zoek', 'url' => '/api/zgw/zaken/v1/zaken/_zoek', 'verb' => 'POST'],
        // Audit trail.
        ['name' => 'zrc#audittrailIndex', 'url' => '/api/zgw/zaken/v1/{resource}/{uuid}/audittrail', 'verb' => 'GET'],
        ['name' => 'zrc#audittrailShow', 'url' => '/api/zgw/zaken/v1/{resource}/{uuid}/audittrail/{auditUuid}', 'verb' => 'GET'],
        // CRUD.
        ['name' => 'zrc#index', 'url' => '/api/zgw/zaken/v1/{resource}', 'verb' => 'GET'],
        ['name' => 'zrc#create', 'url' => '/api/zgw/zaken/v1/{resource}', 'verb' => 'POST'],
        ['name' => 'zrc#show', 'url' => '/api/zgw/zaken/v1/{resource}/{uuid}', 'verb' => 'GET'],
        ['name' => 'zrc#update', 'url' => '/api/zgw/zaken/v1/{resource}/{uuid}', 'verb' => 'PUT'],
        ['name' => 'zrc#patch', 'url' => '/api/zgw/zaken/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
        ['name' => 'zrc#destroy', 'url' => '/api/zgw/zaken/v1/{resource}/{uuid}', 'verb' => 'DELETE'],

        // ── ZTC (Catalogi) ──────────────────────────────────────────────
        // Publish endpoints (must precede wildcard routes).
        ['name' => 'ztc#publishZaaktype', 'url' => '/api/zgw/catalogi/v1/zaaktypen/{uuid}/publish', 'verb' => 'POST'],
        ['name' => 'ztc#publishBesluittype', 'url' => '/api/zgw/catalogi/v1/besluittypen/{uuid}/publish', 'verb' => 'POST'],
        ['name' => 'ztc#publishInformatieobjecttype', 'url' => '/api/zgw/catalogi/v1/informatieobjecttypen/{uuid}/publish', 'verb' => 'POST'],
        // Audit trail.
        ['name' => 'ztc#audittrailIndex', 'url' => '/api/zgw/catalogi/v1/{resource}/{uuid}/audittrail', 'verb' => 'GET'],
        ['name' => 'ztc#audittrailShow', 'url' => '/api/zgw/catalogi/v1/{resource}/{uuid}/audittrail/{auditUuid}', 'verb' => 'GET'],
        // CRUD.
        ['name' => 'ztc#index', 'url' => '/api/zgw/catalogi/v1/{resource}', 'verb' => 'GET'],
        ['name' => 'ztc#create', 'url' => '/api/zgw/catalogi/v1/{resource}', 'verb' => 'POST'],
        ['name' => 'ztc#show', 'url' => '/api/zgw/catalogi/v1/{resource}/{uuid}', 'verb' => 'GET'],
        ['name' => 'ztc#update', 'url' => '/api/zgw/catalogi/v1/{resource}/{uuid}', 'verb' => 'PUT'],
        ['name' => 'ztc#patch', 'url' => '/api/zgw/catalogi/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
        ['name' => 'ztc#destroy', 'url' => '/api/zgw/catalogi/v1/{resource}/{uuid}', 'verb' => 'DELETE'],

        // ── BRC (Besluiten) ─────────────────────────────────────────────
        // Audit trail.
        ['name' => 'brc#audittrailIndex', 'url' => '/api/zgw/besluiten/v1/{resource}/{uuid}/audittrail', 'verb' => 'GET'],
        ['name' => 'brc#audittrailShow', 'url' => '/api/zgw/besluiten/v1/{resource}/{uuid}/audittrail/{auditUuid}', 'verb' => 'GET'],
        // CRUD.
        ['name' => 'brc#index', 'url' => '/api/zgw/besluiten/v1/{resource}', 'verb' => 'GET'],
        ['name' => 'brc#create', 'url' => '/api/zgw/besluiten/v1/{resource}', 'verb' => 'POST'],
        ['name' => 'brc#show', 'url' => '/api/zgw/besluiten/v1/{resource}/{uuid}', 'verb' => 'GET'],
        ['name' => 'brc#update', 'url' => '/api/zgw/besluiten/v1/{resource}/{uuid}', 'verb' => 'PUT'],
        ['name' => 'brc#patch', 'url' => '/api/zgw/besluiten/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
        ['name' => 'brc#destroy', 'url' => '/api/zgw/besluiten/v1/{resource}/{uuid}', 'verb' => 'DELETE'],

        // ── AC (Autorisaties) ───────────────────────────────────────────
        ['name' => 'ac#index', 'url' => '/api/zgw/autorisaties/v1/applicaties', 'verb' => 'GET'],
        ['name' => 'ac#create', 'url' => '/api/zgw/autorisaties/v1/applicaties', 'verb' => 'POST'],
        ['name' => 'ac#show', 'url' => '/api/zgw/autorisaties/v1/applicaties/{uuid}', 'verb' => 'GET'],
        ['name' => 'ac#update', 'url' => '/api/zgw/autorisaties/v1/applicaties/{uuid}', 'verb' => 'PUT'],
        ['name' => 'ac#patch', 'url' => '/api/zgw/autorisaties/v1/applicaties/{uuid}', 'verb' => 'PATCH'],
        ['name' => 'ac#destroy', 'url' => '/api/zgw/autorisaties/v1/applicaties/{uuid}', 'verb' => 'DELETE'],

        // ── NRC (Notificaties) ──────────────────────────────────────────
        // Notificaties webhook endpoint.
        ['name' => 'nrc#notificatieCreate', 'url' => '/api/zgw/notificaties/v1/notificaties', 'verb' => 'POST'],
        // Audit trail.
        ['name' => 'nrc#audittrailIndex', 'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}/audittrail', 'verb' => 'GET'],
        ['name' => 'nrc#audittrailShow', 'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}/audittrail/{auditUuid}', 'verb' => 'GET'],
        // CRUD.
        ['name' => 'nrc#index', 'url' => '/api/zgw/notificaties/v1/{resource}', 'verb' => 'GET'],
        ['name' => 'nrc#create', 'url' => '/api/zgw/notificaties/v1/{resource}', 'verb' => 'POST'],
        ['name' => 'nrc#show', 'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}', 'verb' => 'GET'],
        ['name' => 'nrc#update', 'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}', 'verb' => 'PUT'],
        ['name' => 'nrc#patch', 'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}', 'verb' => 'PATCH'],
        ['name' => 'nrc#destroy', 'url' => '/api/zgw/notificaties/v1/{resource}/{uuid}', 'verb' => 'DELETE'],

        // GIS / cases-on-map: the multi-object overview is served by
        // OpenRegister's page-level maps-overview surface (OR #154) — RBAC-scoped
        // marker points at /apps/openregister/api/integrations/maps/overviews/...
        // Procest's bespoke GIS-proxy / WMS-WFS-proxy / WFS-export / WFS-XML /
        // map-layer-CRUD / cases-geo routes were removed with that migration
        // (issue #112, ADR-022). PDOK address resolution is owned separately by
        // the migrate-pdok-to-openconnector change.

        // ── Parafeerroute (B&W parafering engine) ───────────────────────
        // CRUD on parafeerroute objects is served by OpenRegister's auto-exposed
        // /api/objects/<register>/<schema> endpoints — only engine routes remain.
        ['name' => 'parafeerRoute#start',        'url' => '/api/parafeer-route/voorstel/{voorstelId}/start',          'verb' => 'POST'],
        ['name' => 'parafeerRoute#completeStep', 'url' => '/api/parafeer-route/voorstel/{voorstelId}/complete-step',  'verb' => 'POST'],
        ['name' => 'parafeerRoute#skipStep',     'url' => '/api/parafeer-route/voorstel/{voorstelId}/skip-step',      'verb' => 'POST'],
        ['name' => 'parafeerRoute#addStep',      'url' => '/api/parafeer-route/voorstel/{voorstelId}/add-step',       'verb' => 'POST'],

        // Voorstel → besluit registration delegates to a decidesk report-adoption
        // Decision (procest-delegate-remaining-decisions-to-decidesk, ADR-019).
        // The parafeerroute above is untouched; only the besluit decision moves.
        ['name' => 'voorstelBesluit#registerBesluit', 'url' => '/api/voorstellen/{voorstelId}/register-besluit',       'verb' => 'POST'],

        // NOTE: ParaferingController + ParaferingService were superseded scaffolding
        // that operated entirely in-memory (no persistence, client-supplied state).
        // Deleted in wave-3 security fix. The live engine is ParafeerActieService /
        // ParafeerRouteController. Audit-trail export route retained below.
        // Parafering audit trail Archiefwet-aligned export (action, not CRUD).
        // CRUD on paraferingAuditEntry objects is served by OpenRegister's
        // auto-exposed /api/objects/<register>/<schema> endpoints — only the
        // export action lives here.
        ['name' => 'paraferingAuditExport#export', 'url' => '/api/voorstellen/{id}/audit-trail/export',              'verb' => 'GET'],

        // ── StUF (Standaard Uitwisselings Formaat) ──────────────────────
        // Inbound SOAP endpoints accept raw XML POST.
        ['name' => 'stuf#zaken',    'url' => '/api/stuf/zaken',    'verb' => 'POST'],
        ['name' => 'stuf#personen', 'url' => '/api/stuf/personen', 'verb' => 'POST'],
        // Outbound StUF-ZKN gateway (admin REST) + async confirmation receiver.
        ['name' => 'stuf#endpoints', 'url' => '/api/stuf/endpoints', 'verb' => 'GET'],
        ['name' => 'stuf#messages',  'url' => '/api/stuf/messages',  'verb' => 'GET'],
        ['name' => 'stuf#outbound',  'url' => '/api/stuf/outbound',  'verb' => 'POST'],
        ['name' => 'stuf#inkomend',  'url' => '/api/stuf/inkomend',  'verb' => 'POST'],

        // Doorlooptijd (throughput-time) dashboard metrics.
        ['name' => 'doorlooptijd#metrics', 'url' => '/api/doorlooptijd/metrics', 'verb' => 'GET'],

        // Deelzaak (sub-case) parent-child relations.
        ['name' => 'deelzaak#counts',   'url' => '/api/deelzaken/counts',                'verb' => 'GET'],
        ['name' => 'deelzaak#validate', 'url' => '/api/deelzaken/validate',              'verb' => 'POST'],
        ['name' => 'deelzaak#list',     'url' => '/api/deelzaken/{caseId}/children',     'verb' => 'GET'],
        ['name' => 'deelzaak#parent',   'url' => '/api/deelzaken/{caseId}/parent',       'verb' => 'GET'],
        ['name' => 'deelzaak#unlink',   'url' => '/api/deelzaken/{caseId}/unlink',       'verb' => 'POST'],

        // Related-case linking — typed peer relations (relevanteAndereZaken).
        ['name' => 'caseRelation#list',    'url' => '/api/cases/{caseId}/relations',                          'verb' => 'GET'],
        ['name' => 'caseRelation#create',  'url' => '/api/cases/{caseId}/relations',                          'verb' => 'POST'],
        ['name' => 'caseRelation#destroy', 'url' => '/api/cases/{caseId}/relations/{targetId}/{aardRelatie}', 'verb' => 'DELETE'],
        // Dashboard KPI aggregation endpoint.
        ['name' => 'kpi#index', 'url' => '/api/dashboard/kpis', 'verb' => 'GET'],

        // ── Mobile Inspection (PWA) ─────────────────────────────────────
        ['name' => 'inspection#index',                'url' => '/api/inspections',                                   'verb' => 'GET'],
        ['name' => 'inspection#captureLocation',      'url' => '/api/inspections/{id}/location',                     'verb' => 'POST'],
        ['name' => 'inspection#completeChecklistItem','url' => '/api/inspections/{id}/checklist/{itemId}',           'verb' => 'POST'],
        ['name' => 'inspection#addPhoto',             'url' => '/api/inspections/{id}/photos',                       'verb' => 'POST'],
        ['name' => 'inspection#complete',             'url' => '/api/inspections/{id}/complete',                     'verb' => 'POST'],

        // ── Mobiel Inspectie Offline (sync queue + conflict resolution) ─
        // Static/verb routes precede the {id} wildcards.
        ['name' => 'sync#daily',         'url' => '/api/sync/daily',                       'verb' => 'GET'],
        ['name' => 'sync#queue',         'url' => '/api/sync/queue',                       'verb' => 'GET'],
        ['name' => 'sync#recordOutcome', 'url' => '/api/sync/queue/{id}/outcome',          'verb' => 'POST'],
        ['name' => 'sync#resolveConflict', 'url' => '/api/sync/conflicts/{id}/resolve',    'verb' => 'POST'],
        // PWA assets (must precede the catch-all /{path} shell route below).
        ['name' => 'dashboard#serviceWorker', 'url' => '/service-worker.js',           'verb' => 'GET'],
        ['name' => 'dashboard#webManifest',   'url' => '/manifest.webmanifest',        'verb' => 'GET'],

        // ── Legesberekening (municipal fee calculation) ─────────────────
        ['name' => 'leges#calculate',   'url' => '/api/leges/calculate',    'verb' => 'POST'],
        ['name' => 'leges#recalculate', 'url' => '/api/leges/recalculate',  'verb' => 'POST'],
        ['name' => 'leges#verrekening', 'url' => '/api/leges/verrekening',  'verb' => 'POST'],
        ['name' => 'leges#teruggaaf',   'url' => '/api/leges/teruggaaf',    'verb' => 'POST'],
        ['name' => 'leges#export',      'url' => '/api/leges/export',       'verb' => 'POST'],

        // ── Leges-heffingen: verordening administration (admin-gated) ────
        // Static routes precede the {id} wildcard routes (ADR-016).
        ['name' => 'legesAdmin#importVerordening',  'url' => '/api/leges/import-verordening',           'verb' => 'POST'],
        ['name' => 'legesAdmin#listVerordeningen',  'url' => '/api/admin/leges/verordeningen',          'verb' => 'GET'],
        ['name' => 'legesAdmin#updateVerordening',  'url' => '/api/admin/leges/verordeningen/{id}',     'verb' => 'PATCH'],
        ['name' => 'legesAdmin#approveVerordening', 'url' => '/api/admin/leges/verordeningen/{id}/approve', 'verb' => 'POST'],

        // ── Leges-heffingen: per-case calculation/refund (normal users) ──
        ['name' => 'legesCase#calculate',  'url' => '/api/cases/{caseId}/leges/calculate',    'verb' => 'POST'],
        ['name' => 'legesCase#auditTrail', 'url' => '/api/cases/{caseId}/leges/audit-trail',  'verb' => 'GET'],
        ['name' => 'legesCase#refund',     'url' => '/api/cases/{caseId}/leges/refund',       'verb' => 'POST'],
        ['name' => 'legesCase#show',       'url' => '/api/cases/{caseId}/leges',              'verb' => 'GET'],

        // ── Advice Management (adviesAanvraag) ──────────────────────────
        // CRUD is handled by the manifest renderer via OpenRegister. Only
        // workflow operations live on this controller.
        ['name' => 'advice#transitionStatus',  'url' => '/api/advice/{id}/transition', 'verb' => 'POST'],
        ['name' => 'advice#dispatchReminder',  'url' => '/api/advice/{id}/remind',     'verb' => 'POST'],

        // ── Workflow Definitions (workflowTemplate) ─────────────────────
        // CRUD on workflowTemplate is served by the manifest renderer +
        // OpenRegister auto-routing (/api/objects/<register>/<schema>).
        // Only the lifecycle transitions and the read-only consumer
        // contract live on this controller.
        ['name' => 'workflowDefinition#publish',           'url' => '/api/workflow-definitions/{id}/publish',         'verb' => 'POST'],
        ['name' => 'workflowDefinition#deprecate',         'url' => '/api/workflow-definitions/{id}/deprecate',       'verb' => 'POST'],
        ['name' => 'workflowDefinition#cloneDefinition',   'url' => '/api/workflow-definitions/{id}/clone',           'verb' => 'POST'],
        ['name' => 'workflowDefinition#active',            'url' => '/api/workflow-definitions/active/{caseTypeId}',  'verb' => 'GET'],
        ['name' => 'workflowDefinition#forCase',           'url' => '/api/workflow-definitions/for-case/{caseId}',    'verb' => 'GET'],

        // ── Status Transition Engine ────────────────────────────────────
        // Single write-path for case.status. CRUD on statusRecord is rendered
        // by the manifest (/settings/status-records). Action-style endpoints
        // live here because guard evaluation + side-effect dispatch are
        // non-CRUD engine logic.
        ['name' => 'statusTransition#available', 'url' => '/api/case/{caseId}/available-transitions', 'verb' => 'GET'],
        ['name' => 'statusTransition#execute',   'url' => '/api/case/{caseId}/transition',            'verb' => 'POST'],
        ['name' => 'statusTransition#freeform',  'url' => '/api/case/{caseId}/transition-freeform',   'verb' => 'POST'],
        ['name' => 'statusTransition#history',   'url' => '/api/case/{caseId}/transition-history',    'verb' => 'GET'],

        // Multi-Tenant SaaS — domain endpoints only. Generic tenant CRUD
        // (list/create/update/destroy) is rendered by the manifest pages
        // at /settings/tenants and proxied directly to OpenRegister; this
        // controller keeps provisioning, usage aggregation, and current-
        // tenant resolution — the parts that are not declarative CRUD.
        ['name' => 'tenant#current',   'url' => '/api/tenants/current',                'verb' => 'GET'],
        ['name' => 'tenant#provision', 'url' => '/api/tenants/{tenantId}/provision',   'verb' => 'POST'],
        ['name' => 'tenant#usage',     'url' => '/api/tenants/{tenantId}/usage',       'verb' => 'GET'],

        // SaaS Tenant CRUD + lifecycle — backed by the `tenant` register schema
        // (chain member tenant-zaaksysteem-saas-01). Admin-only via the
        // SecurityMiddleware default; #[AuthorizedAdminSetting] on each method.
        ['name' => 'tenantSaas#index',   'url' => '/api/saas/tenants',                  'verb' => 'GET'],
        ['name' => 'tenantSaas#create',  'url' => '/api/saas/tenants',                  'verb' => 'POST'],
        ['name' => 'tenantSaas#show',    'url' => '/api/saas/tenants/{tenantId}',       'verb' => 'GET'],
        ['name' => 'tenantSaas#update',  'url' => '/api/saas/tenants/{tenantId}',       'verb' => 'PATCH'],
        ['name' => 'tenantSaas#destroy', 'url' => '/api/saas/tenants/{tenantId}',       'verb' => 'DELETE'],

        // SaaS onboarding (chain member 07) — checklist init/progress/complete + go-live activation.
        ['name' => 'tenantOnboarding#initialise', 'url' => '/api/saas/tenants/{tenantId}/onboarding/initialise',     'verb' => 'POST'],
        ['name' => 'tenantOnboarding#progress',   'url' => '/api/saas/tenants/{tenantId}/onboarding/progress',       'verb' => 'GET'],
        ['name' => 'tenantOnboarding#complete',   'url' => '/api/saas/tenants/{tenantId}/onboarding/{step}/complete', 'verb' => 'POST'],
        ['name' => 'tenantOnboarding#activate',   'url' => '/api/saas/tenants/{tenantId}/onboarding/activate',       'verb' => 'POST'],

        // ── Appointment Scheduling (afsprakenbeheer) ────────────────────
        // Specific endpoints (must precede wildcard {appointmentId} routes).
        ['name' => 'appointment#timeslots', 'url' => '/api/appointments/timeslots',                'verb' => 'GET'],
        ['name' => 'appointment#noShow',    'url' => '/api/appointments/{appointmentId}/no-show',  'verb' => 'POST'],
        // CRUD.
        ['name' => 'appointment#index',     'url' => '/api/appointments',                          'verb' => 'GET'],
        ['name' => 'appointment#create',    'url' => '/api/appointments',                          'verb' => 'POST'],
        ['name' => 'appointment#cancel',    'url' => '/api/appointments/{appointmentId}',          'verb' => 'DELETE'],
        // Public (citizen self-service via token).
        ['name' => 'publicAppointment#view',   'url' => '/api/public/appointment/{token}',         'verb' => 'GET'],
        ['name' => 'publicAppointment#cancel', 'url' => '/api/public/appointment/{token}/cancel',  'verb' => 'POST'],

        // Case sharing & collaboration — domain endpoints only.
        // Public "track your case" token links are minted/revoked through
        // OpenRegister's shares integration leaf (ADR-022): createShare
        // delegates to the leaf's case-token surface; revokeShare delegates
        // to the leaf revoke. Partner-organisation handover + case transfer
        // stay in-app (zaak-domain). CRUD over the partner/transfer schemas
        // is served by the OpenRegister manifest renderer.
        //
        // The bespoke procest public-share controller + its token routes
        // (/api/public/share/*, /api/public/status/*) were REMOVED: the
        // citizen-facing public case-status page now resolves anonymously
        // through OR's `#[PublicPage]` endpoint
        // `GET /apps/openregister/api/public/case-tokens/{token}` — an
        // audited, RBAC-respecting surface (only public-group-readable
        // fields), not a hand-maintained procest auth surface.
        ['name' => 'caseSharing#createShare',      'url' => '/api/shares',                   'verb' => 'POST'],
        ['name' => 'caseSharing#revokeShare',      'url' => '/api/shares/{shareId}',         'verb' => 'DELETE'],
        ['name' => 'caseSharing#initiateTransfer', 'url' => '/api/transfers',                'verb' => 'POST'],
        ['name' => 'caseSharing#handleTransfer',   'url' => '/api/transfers/{transferId}',   'verb' => 'PUT'],

        // Role-based routing engine action — manual recompute of step assignees.
        // CRUD of routing rules themselves lives on workflowTemplate (manifest).
        ['name' => 'routing#reroute', 'url' => '/api/cases/{id}/reroute', 'verb' => 'POST'],

        // ── VTH Module: DSO intake, checklist results, advice, LHS lookup ─
        // @spec openspec/changes/vth-module/tasks.md#task-3
        ['name' => 'dSOIntake#intake', 'url' => '/api/vth/dso/intake', 'verb' => 'POST'],
        // @spec openspec/changes/vth-module/tasks.md#task-8
        ['name' => 'lhs#lookup',          'url' => '/api/vth/lhs/lookup', 'verb' => 'GET'],

        // LHS engine actions — matrix lookup + inspector override.
        // CRUD of matrices and recommendations lives on lhsMatrix/lhsRecommendation (manifest).
        ['name' => 'lhs#recommend', 'url' => '/api/lhs/recommend', 'verb' => 'POST'],
        ['name' => 'lhs#override',  'url' => '/api/lhs/override',  'verb' => 'POST'],

        // ── VTH Module ─────────────────────────────────────────────────────
        // VTH zaaktype template management (admin only).
        ['name' => 'vTHTemplate#index',    'url' => '/api/vth/templates',             'verb' => 'GET'],
        ['name' => 'vTHTemplate#activate', 'url' => '/api/vth/templates/{slug}/activate', 'verb' => 'POST'],
        // Inspection checklist CRUD (admin).
        ['name' => 'inspectionChecklist#index',   'url' => '/api/vth/checklists',       'verb' => 'GET'],
        ['name' => 'inspectionChecklist#create',  'url' => '/api/vth/checklists',       'verb' => 'POST'],
        ['name' => 'inspectionChecklist#update',  'url' => '/api/vth/checklists/{id}',  'verb' => 'PUT'],
        ['name' => 'inspectionChecklist#destroy', 'url' => '/api/vth/checklists/{id}',  'verb' => 'DELETE'],
        // Per-case inspection result submission and retrieval.
        ['name' => 'inspectionChecklist#submitResult', 'url' => '/api/vth/cases/{id}/inspection-result', 'verb' => 'POST'],
        ['name' => 'inspectionChecklist#getResults',   'url' => '/api/vth/cases/{id}/inspection-results', 'verb' => 'GET'],
        // Per-case advice request creation.
        ['name' => 'advice#createForCase', 'url' => '/api/vth/cases/{id}/advice-requests', 'verb' => 'POST'],
        ['name' => 'advice#getForCase',    'url' => '/api/vth/cases/{id}/advice-requests', 'verb' => 'GET'],

        // ── Berichtenbox (government inbox integration) ─────────────────
        ['name' => 'berichtenbox#send',     'url' => '/api/berichtenbox/send',                 'verb' => 'POST'],
        ['name' => 'berichtenbox#messages', 'url' => '/api/berichtenbox/messages',             'verb' => 'GET'],
        ['name' => 'berichtenbox#poll',     'url' => '/api/berichtenbox/messages/{messageId}', 'verb' => 'GET'],

        // ── Beschikking (compose -> onderteken -> Berichtenbox -> archief) ──
        // Specific verb/suffix routes precede the generic PATCH /{id} wildcard.
        ['name' => 'beschikking#create',      'url' => '/api/beschikkingen',                       'verb' => 'POST'],
        ['name' => 'beschikking#show',        'url' => '/api/beschikkingen/{id}',                  'verb' => 'GET'],
        ['name' => 'beschikking#auditPakket', 'url' => '/api/beschikkingen/{id}/audit-pakket',     'verb' => 'GET'],
        ['name' => 'beschikking#akkoord',     'url' => '/api/beschikkingen/{id}/akkoord',          'verb' => 'PATCH'],
        ['name' => 'beschikking#onderteken',  'url' => '/api/beschikkingen/{id}/onderteken',       'verb' => 'PATCH'],
        ['name' => 'beschikking#verzend',     'url' => '/api/beschikkingen/{id}/verzend',          'verb' => 'PATCH'],
        ['name' => 'beschikking#update',      'url' => '/api/beschikkingen/{id}',                  'verb' => 'PATCH'],

        // ── Consultation (advice requests and responses) ─────────────────
        ['name' => 'consultation#index',               'url' => '/api/consultations/case/{caseId}',                'verb' => 'GET'],
        ['name' => 'consultation#create',              'url' => '/api/consultations',                              'verb' => 'POST'],
        ['name' => 'consultation#show',                'url' => '/api/consultations/{id}',                         'verb' => 'GET'],
        ['name' => 'consultation#delete',              'url' => '/api/consultations/{id}',                         'verb' => 'DELETE'],
        ['name' => 'consultation#updateStatus',        'url' => '/api/consultations/{id}/status',                  'verb' => 'POST'],
        ['name' => 'consultation#submitResponse',      'url' => '/api/consultations/{id}/response',                'verb' => 'POST'],
        ['name' => 'consultation#requestExtension',    'url' => '/api/consultations/{id}/extension',               'verb' => 'POST'],
        ['name' => 'consultation#approveExtension',    'url' => '/api/consultations/{id}/extension/approve',       'verb' => 'POST'],
        ['name' => 'consultation#overdue',             'url' => '/api/consultations/overdue',                      'verb' => 'GET'],
        ['name' => 'consultation#listAdvisoryBodies',  'url' => '/api/advisory-bodies',                            'verb' => 'GET'],
        ['name' => 'consultation#searchAdvisoryBodies','url' => '/api/advisory-bodies/search',                     'verb' => 'GET'],
        ['name' => 'consultation#publicResponseGet',   'url' => '/api/public/consultations/{token}',               'verb' => 'GET'],
        ['name' => 'consultation#publicResponsePost',  'url' => '/api/public/consultations/{token}',               'verb' => 'POST'],

        // ── Email (outbound case communication) ─────────────────────────
        ['name' => 'email#send',             'url' => '/api/email/{caseId}/send',            'verb' => 'POST'],
        ['name' => 'email#sendFromTemplate', 'url' => '/api/email/{caseId}/send-template',   'verb' => 'POST'],
        ['name' => 'email#preview',          'url' => '/api/email/{caseId}/preview',         'verb' => 'POST'],
        ['name' => 'email#templates',        'url' => '/api/email/templates/{caseTypeId}',   'verb' => 'GET'],

        // Email template versioning + IMAP settings (leaf-first: NC Mail still owns send/list/link).
        ['name' => 'emailTemplate#listTemplates',  'url' => '/api/casetypes/{caseTypeId}/email-templates',                  'verb' => 'GET'],
        ['name' => 'emailTemplate#createTemplate', 'url' => '/api/casetypes/{caseTypeId}/email-templates',                  'verb' => 'POST'],
        ['name' => 'emailTemplate#variables',      'url' => '/api/casetypes/{caseTypeId}/email-templates/variables',         'verb' => 'GET'],
        ['name' => 'emailTemplate#updateTemplate', 'url' => '/api/email-templates/{templateId}',                            'verb' => 'PUT'],
        ['name' => 'emailTemplate#prefillDraft',   'url' => '/api/cases/{caseId}/email-templates/{templateId}/draft',       'verb' => 'POST'],
        ['name' => 'emailTemplate#getSettings',    'url' => '/api/settings/email',                                          'verb' => 'GET'],
        ['name' => 'emailTemplate#saveSettings',   'url' => '/api/settings/email',                                          'verb' => 'PUT'],
        ['name' => 'emailTemplate#testImap',       'url' => '/api/settings/email/test-imap',                                 'verb' => 'POST'],

        // ── Template (workflow step templates) ──────────────────────────
        ['name' => 'template#index',    'url' => '/api/templates',           'verb' => 'GET'],
        ['name' => 'template#show',     'url' => '/api/templates/{id}',      'verb' => 'GET'],
        ['name' => 'template#activate', 'url' => '/api/templates/{id}/activate', 'verb' => 'POST'],

        // ── WOO (Wet open overheid) operations ──────────────────────────
        ['name' => 'wOOAssessment#bulkAssess',      'url' => '/api/cases/{id}/woo/assessment',     'verb' => 'POST'],
        ['name' => 'wOOAssessment#extendDeadline',  'url' => '/api/cases/{id}/woo/extend-deadline','verb' => 'POST'],
        ['name' => 'wOOAssessment#createDecision',  'url' => '/api/cases/{id}/woo/decision',       'verb' => 'POST'],

        // ── Milestone tracking ───────────────────────────────────────────
        ['name' => 'milestone#progress', 'url' => '/api/cases/{caseId}/milestones/progress/{caseTypeId}', 'verb' => 'GET'],
        ['name' => 'milestone#mark',     'url' => '/api/cases/{caseId}/milestones/{milestoneId}/mark',    'verb' => 'POST'],
        ['name' => 'milestone#reverse',  'url' => '/api/cases/{caseId}/milestones/{milestoneId}/reverse', 'verb' => 'POST'],

        // ── Besluitvorming workflow ──────────────────────────────────────
        ['name' => 'besluitvorming#activateTemplate', 'url' => '/api/besluitvorming/templates/{slug}/activate', 'verb' => 'POST'],
        ['name' => 'agenda#addToAgenda',              'url' => '/api/besluitvorming/cases/{id}/agenda',         'verb' => 'POST'],
        ['name' => 'agenda#updateAgendaItem',         'url' => '/api/besluitvorming/cases/{id}/agenda',         'verb' => 'PUT'],
        ['name' => 'publication#publish',             'url' => '/api/besluitvorming/cases/{id}/publish',        'verb' => 'POST'],
        ['name' => 'mandaat#mandaatCheck',            'url' => '/api/besluitvorming/cases/{id}/mandaat-check',  'verb' => 'GET'],

        // GIS map-layer CRUD removed (issue #112): base-layer config is now
        // declarative on OpenRegister's maps-overview surface (PDOK WMTS default,
        // overridable) — procest no longer manages WMS/WFS overlay layers.

        // ── DSO / Omgevingsloket (DSO controller endpoints) ──────────────
        ['name' => 'dso#dashboard',            'url' => '/api/dso/dashboard',                                'verb' => 'GET'],
        ['name' => 'dso#transitionStatus',     'url' => '/api/dso/cases/{caseId}/transition',                'verb' => 'POST'],
        ['name' => 'dso#generateBeschikking',  'url' => '/api/dso/cases/{caseId}/beschikking',               'verb' => 'POST'],
        ['name' => 'dso#initiateSamenwerking', 'url' => '/api/dso/cases/{caseId}/samenwerking',              'verb' => 'POST'],
        ['name' => 'dso#respondSamenwerking',  'url' => '/api/dso/samenwerking/{samenwerkId}/respond',       'verb' => 'POST'],
        ['name' => 'dso#doorsturen',           'url' => '/api/dso/cases/{caseId}/doorsturen',                'verb' => 'POST'],
        ['name' => 'dossierExport#export',     'url' => '/api/dossier/{caseId}/export',                      'verb' => 'GET'],

        // ── KCC-werkplek bridge (kcc-werkplek-zaaksysteem-bridge) ───────
        ['name' => 'contactMoment#create',            'url' => '/api/contactmomenten',                     'verb' => 'POST'],
        ['name' => 'contactMoment#index',             'url' => '/api/contactmomenten',                     'verb' => 'GET'],
        ['name' => 'contactMoment#voorblad',          'url' => '/api/kcc/voorblad',                        'verb' => 'GET'],
        ['name' => 'contactMoment#statusGeven',       'url' => '/api/kcc/quick-actions/status-geven',      'verb' => 'POST'],
        ['name' => 'contactMoment#nieuweZaak',        'url' => '/api/kcc/quick-actions/nieuwe-zaak',       'verb' => 'POST'],
        ['name' => 'contactMoment#klachtRegistreren', 'url' => '/api/kcc/quick-actions/klacht-registreren', 'verb' => 'POST'],
        ['name' => 'contactMoment#doorverbinden',     'url' => '/api/kcc/quick-actions/doorverbinden',     'verb' => 'POST'],
        ['name' => 'contactMoment#acceptDoorverbinding', 'url' => '/api/kcc/doorverbindingen/{id}/accept', 'verb' => 'POST'],
        ['name' => 'contactMoment#rejectDoorverbinding', 'url' => '/api/kcc/doorverbindingen/{id}/reject', 'verb' => 'POST'],
        ['name' => 'belplan#route',                   'url' => '/api/kcc/belplannen/route',                'verb' => 'POST'],
        ['name' => 'belplan#index',                   'url' => '/api/kcc/belplannen',                      'verb' => 'GET'],
        ['name' => 'belplan#create',                  'url' => '/api/kcc/belplannen',                      'verb' => 'POST'],
        ['name' => 'belplan#update',                  'url' => '/api/kcc/belplannen/{id}',                 'verb' => 'PUT'],
        ['name' => 'specialistBeschikbaarheid#index', 'url' => '/api/kcc/specialist-beschikbaarheid',      'verb' => 'GET'],

        // ── Complaints (klachtafhandeling) — Awb chapter 9 ─────────────────
        ['name' => 'complaint#index',              'url' => '/api/complaints',                             'verb' => 'GET'],
        ['name' => 'complaint#create',             'url' => '/api/complaints',                             'verb' => 'POST'],
        // Literal GET sub-paths MUST be registered before the `/{id}` wildcard,
        // otherwise `complaint#show` captures `/complaints/deadline-alerts`,
        // `/complaints/analytics` and `/complaints/kpi` as an `{id}` lookup.
        ['name' => 'complaint#deadlineAlerts',     'url' => '/api/complaints/deadline-alerts',             'verb' => 'GET'],
        ['name' => 'complaint#analytics',          'url' => '/api/complaints/analytics',                  'verb' => 'GET'],
        ['name' => 'complaint#kpi',                'url' => '/api/complaints/kpi',                        'verb' => 'GET'],
        ['name' => 'complaint#show',               'url' => '/api/complaints/{id}',                        'verb' => 'GET'],
        ['name' => 'complaint#update',             'url' => '/api/complaints/{id}',                        'verb' => 'PUT'],
        ['name' => 'complaint#transition',         'url' => '/api/complaints/{id}/transition',             'verb' => 'POST'],
        ['name' => 'complaint#verdaging',          'url' => '/api/complaints/{id}/verdaging',              'verb' => 'POST'],
        ['name' => 'complaint#escalate',           'url' => '/api/complaints/{id}/escalate',               'verb' => 'POST'],
        // Hearings.
        ['name' => 'complaint#hearings',           'url' => '/api/complaints/{id}/hearings',               'verb' => 'GET'],
        ['name' => 'complaint#scheduleHearing',    'url' => '/api/complaints/{id}/hearings',               'verb' => 'POST'],
        ['name' => 'complaint#recordHearingOutcome','url' => '/api/complaints/{id}/hearings/{hearingId}',  'verb' => 'PUT'],
        // Dispositions.
        ['name' => 'complaint#getDisposition',     'url' => '/api/complaints/{id}/disposition',            'verb' => 'GET'],
        ['name' => 'complaint#submitDisposition',  'url' => '/api/complaints/{id}/disposition',            'verb' => 'POST'],
        ['name' => 'complaint#approveDisposition', 'url' => '/api/complaints/{id}/disposition/approve',   'verb' => 'POST'],
        ['name' => 'complaint#generateLetter',     'url' => '/api/complaints/{id}/disposition/letter',    'verb' => 'POST'],
        // Categories (admin).
        ['name' => 'complaint#categories',         'url' => '/api/complaint-categories',                  'verb' => 'GET'],
        ['name' => 'complaint#createCategory',     'url' => '/api/complaint-categories',                  'verb' => 'POST'],
        ['name' => 'complaint#updateCategory',     'url' => '/api/complaint-categories/{id}',             'verb' => 'PUT'],

        // ── Archief / e-Depot handover ─────────────────────────────────────
        ['name' => 'archief#listRules',       'url' => '/api/archief/rules',                'verb' => 'GET'],
        ['name' => 'archief#createRule',      'url' => '/api/archief/rules',                'verb' => 'POST'],
        ['name' => 'archief#updateRule',      'url' => '/api/archief/rules/{ruleId}',       'verb' => 'PUT'],
        ['name' => 'archief#deleteRule',      'url' => '/api/archief/rules/{ruleId}',       'verb' => 'DELETE'],
        ['name' => 'archief#dashboardStats',  'url' => '/api/archief/dashboard/stats',      'verb' => 'GET'],
        ['name' => 'archief#auditLog',        'url' => '/api/archief/audit-log',            'verb' => 'GET'],
        ['name' => 'archief#batchInitiate',   'url' => '/api/archief/batch/initiate',       'verb' => 'POST'],
        ['name' => 'archief#inspectionExport','url' => '/api/archief/inspection-export',    'verb' => 'GET'],
        ['name' => 'archief#retry',           'url' => '/api/archief/triggers/{triggerId}/retry', 'verb' => 'POST'],
        // Wildcard routes registered AFTER literal sub-paths so /batch/initiate doesn't get
        // captured as jobId=initiate (same pattern as complaint#show vs deadline-alerts).
        ['name' => 'archief#batchStatus',     'url' => '/api/archief/batch/{jobId}',        'verb' => 'GET'],
        ['name' => 'archief#batchReport',     'url' => '/api/archief/batch/{jobId}/report', 'verb' => 'GET'],

        // ── Mandaat-matrix authorization engine ────────────────────────────
        ['name' => 'mandaatMatrix#probe',           'url' => '/api/mandate/authorize',                    'verb' => 'POST'],
        ['name' => 'mandaatMatrix#importPreview',   'url' => '/api/mandate/import',                       'verb' => 'POST'],
        ['name' => 'mandaatMatrix#importApprove',   'url' => '/api/mandate/import/{importId}/approve',    'verb' => 'POST'],
        ['name' => 'mandaatMatrix#escalateApprove', 'url' => '/api/mandate/escalations/{id}/approve',     'verb' => 'POST'],
        ['name' => 'mandaatMatrix#escalateReject',  'url' => '/api/mandate/escalations/{id}/reject',      'verb' => 'POST'],
        ['name' => 'mandaatMatrix#auditTrail',      'url' => '/api/mandate/cases/{caseId}/audit-trail',   'verb' => 'GET'],
        ['name' => 'mandaatMatrix#applicable',      'url' => '/api/mandate/cases/{caseId}/applicable',    'verb' => 'GET'],

        // ── Handler vervanging/waarneming + bulk reassignment (handler-vervanging-waarneming) ──
        ['name' => 'substitution#index',           'url' => '/api/substitutions',                 'verb' => 'GET'],
        ['name' => 'substitution#create',          'url' => '/api/substitutions',                 'verb' => 'POST'],
        ['name' => 'substitution#substitutedWork', 'url' => '/api/substitutions/work',            'verb' => 'GET'],
        ['name' => 'substitution#actions',         'url' => '/api/substitutions/{id}/actions',    'verb' => 'GET'],
        ['name' => 'substitution#revoke',          'url' => '/api/substitutions/{id}/revoke',     'verb' => 'POST'],
        ['name' => 'substitution#reassignPreview', 'url' => '/api/reassignments/preview',         'verb' => 'POST'],
        ['name' => 'substitution#reassignExecute', 'url' => '/api/reassignments/execute',         'verb' => 'POST'],

        // ── Termijnbewaking + dwangsom engine (AWB 4:13/4:14/4:17) ─────────
        // Public webhook for openconnector/ERP payment confirmation callbacks.
        ['name' => 'dwangsomPaymentCallback#callback', 'url' => '/api/procest/openconnector/dwangsom-payment-callback', 'verb' => 'POST'],
        // TermijnInstance lifecycle (caseworker / handler).
        ['name' => 'termijn#create',     'url' => '/api/termijn/instances',                  'verb' => 'POST'],
        ['name' => 'termijn#show',       'url' => '/api/termijn/instances/{id}',             'verb' => 'GET'],
        ['name' => 'termijn#pauze',      'url' => '/api/termijn/instances/{id}/pauze',       'verb' => 'POST'],
        ['name' => 'termijn#hervat',     'url' => '/api/termijn/instances/{id}/hervat',      'verb' => 'POST'],
        ['name' => 'termijn#verleng',    'url' => '/api/termijn/instances/{id}/verleng',     'verb' => 'POST'],
        ['name' => 'termijn#voltooi',    'url' => '/api/termijn/instances/{id}/voltooi',     'verb' => 'POST'],
        // Ingebrekestelling registration.
        ['name' => 'ingebrekestelling#register', 'url' => '/api/termijn/ingebrekestellingen',       'verb' => 'POST'],
        ['name' => 'ingebrekestelling#show',     'url' => '/api/termijn/ingebrekestellingen/{id}',  'verb' => 'GET'],
        // Dwangsom state + bezwaar.
        ['name' => 'dwangsom#show',         'url' => '/api/termijn/dwangsom/{id}',             'verb' => 'GET'],
        ['name' => 'dwangsom#beschikking',  'url' => '/api/termijn/dwangsom/{id}/beschikking', 'verb' => 'POST'],
        ['name' => 'dwangsom#bezwaar',      'url' => '/api/termijn/dwangsom/{id}/bezwaar',     'verb' => 'POST'],
        ['name' => 'dwangsom#bezwaarHeroverweging', 'url' => '/api/termijn/dwangsom/{id}/bezwaar/heroverweging', 'verb' => 'POST'],
        // Reporting (manager / accountant).
        ['name' => 'termijnReporting#dashboard',         'url' => '/api/termijn/dashboard/kpi',            'verb' => 'GET'],
        ['name' => 'termijnReporting#kwartaalrapport',   'url' => '/api/termijn/reports/kwartaal',         'verb' => 'GET'],
        ['name' => 'termijnReporting#jaarrekening',      'url' => '/api/termijn/reports/jaarrekening',     'verb' => 'GET'],

        // ── ZGW DRC Case Dossier (document-zaakdossier spec) ────────────
        // Specific endpoints precede the {infoObjectId} wildcards so bulk/status routes resolve first.
        ['name' => 'zaakdossier#listDossier',          'url' => '/api/cases/{caseId}/dossier',                     'verb' => 'GET'],
        ['name' => 'zaakdossier#uploadDocument',       'url' => '/api/cases/{caseId}/dossier',                     'verb' => 'POST'],
        ['name' => 'zaakdossier#downloadZip',          'url' => '/api/cases/{caseId}/dossier/zip',                 'verb' => 'POST'],
        ['name' => 'zaakdossier#linkExisting',         'url' => '/api/cases/{caseId}/dossier/{infoObjectId}/link', 'verb' => 'POST'],
        ['name' => 'zaakdossier#unlinkDocument',       'url' => '/api/cases/{caseId}/dossier/{infoObjectId}/link', 'verb' => 'DELETE'],
        ['name' => 'zaakdossier#bulkTransitionStatus', 'url' => '/api/informatieobjecten/bulk/status',            'verb' => 'POST'],
        ['name' => 'zaakdossier#bulkUpdateMetadata',   'url' => '/api/informatieobjecten/bulk/metadata',          'verb' => 'POST'],
        ['name' => 'zaakdossier#transitionStatus',     'url' => '/api/informatieobjecten/{infoObjectId}/status',   'verb' => 'PATCH'],
        ['name' => 'zaakdossier#updateMetadata',       'url' => '/api/informatieobjecten/{infoObjectId}',          'verb' => 'PATCH'],
        ['name' => 'zaakdossier#downloadFile',         'url' => '/api/objects/{register}/{schema}/{objectId}/files/{fileId}/download', 'verb' => 'GET'],
        ['name' => 'zaakdossier#downloadZgwDocumenten','url' => '/api/zgw/documenten/v1/enkelvoudiginformatieobjecten/{uuid}/download', 'verb' => 'GET'],

        // ── ORI Atom Feeds (public, no auth required) ───────────────────
        ['name' => 'raadsinformatieFeed#vergaderingen', 'url' => '/feed/ori/vergaderingen.rss', 'verb' => 'GET'],
        ['name' => 'raadsinformatieFeed#agendapunten',  'url' => '/feed/ori/agendapunten.rss',  'verb' => 'GET'],
        ['name' => 'raadsinformatieFeed#documenten',    'url' => '/feed/ori/documenten.rss',    'verb' => 'GET'],

        // NOTE: dashboard#page (`/`) and the SPA catch-all (`/{path}`,
        // dashboard#catchAll) are supplied by Routes::standard(); both resolve to
        // procest's DashboardController, which extends GenericDashboardController.
]);
