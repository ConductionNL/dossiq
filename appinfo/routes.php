<?php

/**
 * Procest Route Configuration
 *
 * Defines all HTTP routes for the Procest application.
 *
 * @category Routes
 * @package  OCA\Procest
 *
 * @author    Conduction Development Team <dev@conductio.nl>
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

return [
    'routes' => [
        // Dashboard + Settings.
        ['name' => 'dashboard#page', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'settings#index', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'settings#create', 'url' => '/api/settings', 'verb' => 'POST'],
        ['name' => 'settings#load',  'url' => '/api/settings/load', 'verb' => 'POST'],

        // AI-Assisted Processing (specific endpoints precede wildcard routes).
        ['name' => 'ai#classify',        'url' => '/api/ai/classify',        'verb' => 'POST'],
        ['name' => 'ai#extract',         'url' => '/api/ai/extract',         'verb' => 'POST'],
        ['name' => 'ai#ask',             'url' => '/api/ai/ask',             'verb' => 'POST'],
        ['name' => 'ai#summarize',       'url' => '/api/ai/summarize',       'verb' => 'POST'],
        ['name' => 'ai#suggestRouting',  'url' => '/api/ai/suggest-routing', 'verb' => 'POST'],
        ['name' => 'ai#suggestNext',     'url' => '/api/ai/suggest-next',    'verb' => 'POST'],
        ['name' => 'ai#recordAction',    'url' => '/api/ai/record-action',   'verb' => 'POST'],
        ['name' => 'ai#auditIndex',      'url' => '/api/ai/audit',           'verb' => 'GET'],
        ['name' => 'ai#getSettings',     'url' => '/api/ai/settings',        'verb' => 'GET'],
        ['name' => 'ai#updateSettings',  'url' => '/api/ai/settings',        'verb' => 'POST'],
        ['name' => 'ai#healthCheck',     'url' => '/api/ai/health',          'verb' => 'POST'],

        // Parafering Actions (must precede any wildcard routes).
        ['name' => 'parafeer_actie#create', 'url' => '/api/parafeer-actie', 'verb' => 'POST'],
        ['name' => 'parafeer_actie#index',  'url' => '/api/parafeer-actie', 'verb' => 'GET'],

        // ZGW Mapping Management.
        ['name' => 'zgw_mapping#index', 'url' => '/api/zgw-mappings', 'verb' => 'GET'],
        ['name' => 'zgw_mapping#show', 'url' => '/api/zgw-mappings/{resourceKey}', 'verb' => 'GET'],
        ['name' => 'zgw_mapping#update', 'url' => '/api/zgw-mappings/{resourceKey}', 'verb' => 'PUT'],
        ['name' => 'zgw_mapping#destroy', 'url' => '/api/zgw-mappings/{resourceKey}', 'verb' => 'DELETE'],
        ['name' => 'zgw_mapping#reset', 'url' => '/api/zgw-mappings/{resourceKey}/reset', 'verb' => 'POST'],

        // Case Definition Portability (export/import zaaktype packages).
        ['name' => 'case_definition#export', 'url' => '/api/case-definitions/export', 'verb' => 'POST'],
        ['name' => 'case_definition#validate', 'url' => '/api/case-definitions/validate', 'verb' => 'POST'],
        ['name' => 'case_definition#import', 'url' => '/api/case-definitions/import', 'verb' => 'POST'],

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

        // GIS Proxy endpoints.
        ['name' => 'gis_proxy#proxy', 'url' => '/api/gis/proxy', 'verb' => 'POST'],
        ['name' => 'gis_proxy#capabilities', 'url' => '/api/gis/capabilities', 'verb' => 'GET'],

        // ── Parafeerroute (B&W parafering engine) ───────────────────────
        ['name' => 'parafeer_route#index',        'url' => '/api/parafeer-route',                                      'verb' => 'GET'],
        ['name' => 'parafeer_route#create',       'url' => '/api/parafeer-route',                                      'verb' => 'POST'],
        ['name' => 'parafeer_route#start',        'url' => '/api/parafeer-route/voorstel/{voorstelId}/start',          'verb' => 'POST'],
        ['name' => 'parafeer_route#completeStep', 'url' => '/api/parafeer-route/voorstel/{voorstelId}/complete-step',  'verb' => 'POST'],
        ['name' => 'parafeer_route#skipStep',     'url' => '/api/parafeer-route/voorstel/{voorstelId}/skip-step',      'verb' => 'POST'],
        ['name' => 'parafeer_route#addStep',      'url' => '/api/parafeer-route/voorstel/{voorstelId}/add-step',       'verb' => 'POST'],
        ['name' => 'parafeer_route#show',         'url' => '/api/parafeer-route/{id}',                                 'verb' => 'GET'],
        ['name' => 'parafeer_route#update',       'url' => '/api/parafeer-route/{id}',                                 'verb' => 'PUT'],
        ['name' => 'parafeer_route#destroy',      'url' => '/api/parafeer-route/{id}',                                 'verb' => 'DELETE'],

        // ── B&W Parafering (voorstellen workflow) ───────────────────────
        ['name' => 'parafering#createVoorstel',   'url' => '/api/parafering/voorstellen',                              'verb' => 'POST'],
        ['name' => 'parafering#startParafering',  'url' => '/api/parafering/voorstellen/{id}/start',                   'verb' => 'POST'],
        ['name' => 'parafering#paraferen',        'url' => '/api/parafering/voorstellen/{id}/paraferen',               'verb' => 'POST'],
        ['name' => 'parafering#terugsturen',     'url' => '/api/parafering/voorstellen/{id}/terugsturen',             'verb' => 'POST'],
        ['name' => 'parafering#adviseren',        'url' => '/api/parafering/voorstellen/{id}/adviseren',               'verb' => 'POST'],
        ['name' => 'parafering#auditTrail',       'url' => '/api/parafering/voorstellen/{id}/audit-trail',             'verb' => 'GET'],

        // ── StUF (Standaard Uitwisselings Formaat) ──────────────────────
        // Inbound SOAP endpoints accept raw XML POST.
        ['name' => 'stuf#zaken',    'url' => '/api/stuf/zaken',    'verb' => 'POST'],
        ['name' => 'stuf#personen', 'url' => '/api/stuf/personen', 'verb' => 'POST'],

        // Prometheus metrics endpoint.
        ['name' => 'metrics#index', 'url' => '/api/metrics', 'verb' => 'GET'],
        // Health check endpoint.
        ['name' => 'health#index', 'url' => '/api/health', 'verb' => 'GET'],
        // Dashboard KPI aggregation endpoint.
        ['name' => 'kpi#index', 'url' => '/api/dashboard/kpis', 'verb' => 'GET'],

        // ── Mobile Inspection (PWA) ─────────────────────────────────────
        ['name' => 'inspection#index',                'url' => '/api/inspections',                                   'verb' => 'GET'],
        ['name' => 'inspection#captureLocation',      'url' => '/api/inspections/{id}/location',                     'verb' => 'POST'],
        ['name' => 'inspection#completeChecklistItem','url' => '/api/inspections/{id}/checklist/{itemId}',           'verb' => 'POST'],
        ['name' => 'inspection#addPhoto',             'url' => '/api/inspections/{id}/photos',                       'verb' => 'POST'],
        ['name' => 'inspection#complete',             'url' => '/api/inspections/{id}/complete',                     'verb' => 'POST'],

        // ── Legesberekening (municipal fee calculation) ─────────────────
        ['name' => 'leges#calculate',   'url' => '/api/leges/calculate',    'verb' => 'POST'],
        ['name' => 'leges#recalculate', 'url' => '/api/leges/recalculate',  'verb' => 'POST'],
        ['name' => 'leges#verrekening', 'url' => '/api/leges/verrekening',  'verb' => 'POST'],
        ['name' => 'leges#teruggaaf',   'url' => '/api/leges/teruggaaf',    'verb' => 'POST'],
        ['name' => 'leges#export',      'url' => '/api/leges/export',       'verb' => 'POST'],

        // Multi-Tenant SaaS — tenant management endpoints (platform admin scoped).
        ['name' => 'tenant#current',   'url' => '/api/tenants/current',                'verb' => 'GET'],
        ['name' => 'tenant#index',     'url' => '/api/tenants',                        'verb' => 'GET'],
        ['name' => 'tenant#create',    'url' => '/api/tenants',                        'verb' => 'POST'],
        ['name' => 'tenant#provision', 'url' => '/api/tenants/{tenantId}/provision',   'verb' => 'POST'],
        ['name' => 'tenant#usage',     'url' => '/api/tenants/{tenantId}/usage',       'verb' => 'GET'],

        // SPA catch-all — serves the Vue app for any frontend route (history mode).
        ['name' => 'dashboard#page', 'url' => '/{path}', 'verb' => 'GET', 'requirements' => ['path' => '.+'], 'defaults' => ['path' => '']],
    ],
];
