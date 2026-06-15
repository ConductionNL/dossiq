<?php

/**
 * Procest Supplier Portal Controller
 *
 * Operator-side read endpoints that surface the leverancier-zaakportaal
 * dashboard, tender list, invoice list, contract list, KPI summary and
 * supplier-messaging thread to a Nextcloud-authenticated procurement
 * officer. Supplier-facing eHerkenning + JWT bearer flow is exposed by
 * a separate edge entry-point (chain member 02 — deferred); this
 * controller serves the Vue components shipped in chain members 06/08/
 * 10/11/14/15 against the existing supplier services.
 *
 * Every endpoint is `#[NoAdminRequired]` because procurement officers
 * are NOT necessarily NC admins — the SecurityMiddleware admin default
 * is too strict. Cross-supplier IDOR is prevented by deriving the
 * `supplierRef` from the SERVER-TRUSTED supplier session
 * (`SupplierSessionService::requireSupplierRef()`, validated from the
 * portal bearer JWT) — never from a client-supplied request parameter —
 * and failing CLOSED with 401 when no valid session is present. Every
 * read then goes through `SupplierScopeService::listSupplierObjects()`
 * which filters by that session `supplierRef`.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/leverancier-zaakportaal-15-dashboard-shell/tasks.md
 * @spec openspec/changes/leverancier-zaakportaal-06-tender-frontend/tasks.md
 * @spec openspec/changes/leverancier-zaakportaal-08-invoice-frontend/tasks.md
 * @spec openspec/changes/leverancier-zaakportaal-10-contract-frontend/tasks.md
 * @spec openspec/changes/leverancier-zaakportaal-11-messaging/tasks.md
 * @spec openspec/changes/leverancier-zaakportaal-14-kpi-frontend/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Middleware\SupplierUnauthorizedException;
use OCA\Procest\Service\LeverancierViewModelService;
use OCA\Procest\Service\SupplierDashboardService;
use OCA\Procest\Service\SupplierKpiAggregationService;
use OCA\Procest\Service\SupplierMessageService;
use OCA\Procest\Service\SupplierScopeService;
use OCA\Procest\Service\SupplierSessionService;
use OCA\Procest\Service\TenderViewModelService;
use OCA\Procest\Service\TenderVisibilityService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Internal-operator-facing endpoints for the leverancier-zaakportaal Vue surface.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — façade delegating to
 * already-bounded supplier services; coupling is to those collaborators only.
 */
class SupplierPortalController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                     $request          The request.
     * @param SupplierScopeService         $scope            Scope + masking.
     * @param SupplierDashboardService     $dashboard        Dashboard aggregator.
     * @param TenderViewModelService       $tenderViewModel  Tender badge + cache helpers.
     * @param TenderVisibilityService      $tenderVisibility Tender visibility flags.
     * @param LeverancierViewModelService  $viewModel        Invoice/contract view-model helpers.
     * @param SupplierMessageService       $messages         Threaded messaging.
     * @param SupplierKpiAggregationService $kpi             KPI aggregation.
     * @param SupplierSessionService       $session          Server-trusted supplier session resolver.
     */
    public function __construct(
        IRequest $request,
        private readonly SupplierScopeService $scope,
        private readonly SupplierDashboardService $dashboard,
        private readonly TenderViewModelService $tenderViewModel,
        private readonly TenderVisibilityService $tenderVisibility,
        private readonly LeverancierViewModelService $viewModel,
        private readonly SupplierMessageService $messages,
        private readonly SupplierKpiAggregationService $kpi,
        private readonly SupplierSessionService $session,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Resolve the server-trusted supplier reference for the calling session.
     *
     * Fails CLOSED: returns a 401 JSON response (as the `$error` out-param) when
     * no valid supplier session is present. The caller MUST short-circuit on a
     * non-null error before touching any supplier-scoped object.
     *
     * @param JSONResponse|null $error Set to a 401 response when no valid session.
     *
     * @return string The server-trusted supplier reference ('' when unauthenticated).
     */
    private function resolveSupplierRef(?JSONResponse &$error): string
    {
        $error = null;
        try {
            return $this->session->requireSupplierRef();
        } catch (SupplierUnauthorizedException $e) {
            $error = new JSONResponse(['error' => 'Bearer token required'], Http::STATUS_UNAUTHORIZED);
            return '';
        }
    }//end resolveSupplierRef()

    /**
     * Dashboard summary — 4 cards.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/leverancier-zaakportaal-15-dashboard-shell/tasks.md
     */
    public function dashboard(): JSONResponse
    {
        $supplierRef = $this->resolveSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        return new JSONResponse($this->dashboard->buildSummary($supplierRef, time()));
    }//end dashboard()

    /**
     * Tender list — filterable by status.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/leverancier-zaakportaal-06-tender-frontend/tasks.md
     */
    public function tenders(): JSONResponse
    {
        $supplierRef = $this->resolveSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        $status     = (string) $this->request->getParam('status', '');
        $filters    = $status !== '' ? ['status' => $status] : [];
        $tenders    = $this->scope->listSupplierObjects($supplierRef, 'supplierTender', $filters);
        $rows       = array_map(function (array $t): array {
            $t['badgeColor']  = $this->tenderViewModel->badgeColor((string) ($t['status'] ?? ''));
            $t['visibility']  = $this->tenderViewModel->visibilityFlags($t);
            return $t;
        }, $tenders);

        $resp = new JSONResponse(['items' => $rows, 'total' => count($rows)]);
        $resp->addHeader('Cache-Control', $this->tenderViewModel->cacheControlHeader());
        return $resp;
    }//end tenders()

    /**
     * Tender detail.
     *
     * @param string $id Tender UUID.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/leverancier-zaakportaal-06-tender-frontend/tasks.md
     */
    public function tenderDetail(string $id): JSONResponse
    {
        $supplierRef = $this->resolveSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        $tenders = $this->scope->listSupplierObjects($supplierRef, 'supplierTender');
        foreach ($tenders as $t) {
            if ((string) ($t['id'] ?? $t['uuid'] ?? '') === $id) {
                $t['badgeColor'] = $this->tenderViewModel->badgeColor((string) ($t['status'] ?? ''));
                $t['visibility']    = $this->tenderViewModel->visibilityFlags($t);
                $t['appealDeadline']= $this->tenderVisibility->getAppealDeadline($t);
                $t['canAppeal']     = $this->tenderVisibility->canAppeal($t);
                return new JSONResponse($t);
            }
        }

        return new JSONResponse(['error' => 'not found'], Http::STATUS_NOT_FOUND);
    }//end tenderDetail()

    /**
     * Invoice list — bound to the InvoiceList Vue component.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/leverancier-zaakportaal-08-invoice-frontend/tasks.md
     */
    public function invoices(): JSONResponse
    {
        $supplierRef = $this->resolveSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        $invoices = $this->scope->listSupplierObjects($supplierRef, 'supplierInvoice');
        $nowTs    = time();
        $rows     = array_map(function (array $inv) use ($nowTs): array {
            $inv['badgeColor']    = $this->viewModel->invoiceBadgeColor((string) ($inv['status'] ?? ''));
            $inv['overdue90Plus'] = $this->viewModel->isOverdue90Plus($inv, $nowTs);
            return $inv;
        }, $invoices);

        return new JSONResponse(['items' => $rows, 'total' => count($rows)]);
    }//end invoices()

    /**
     * Contract list.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/leverancier-zaakportaal-10-contract-frontend/tasks.md
     */
    public function contracts(): JSONResponse
    {
        $supplierRef = $this->resolveSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        $contracts = $this->scope->listSupplierObjects($supplierRef, 'supplierContract');
        return new JSONResponse(['items' => $contracts, 'total' => count($contracts)]);
    }//end contracts()

    /**
     * KPI summary.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/leverancier-zaakportaal-14-kpi-frontend/tasks.md
     */
    public function kpi(): JSONResponse
    {
        $supplierRef = $this->resolveSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        $invoices = $this->scope->listSupplierObjects($supplierRef, 'supplierInvoice');
        return new JSONResponse($this->kpi->aggregateKpis($invoices));
    }//end kpi()

    /**
     * Message thread list.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/leverancier-zaakportaal-11-messaging/tasks.md
     */
    public function messages(): JSONResponse
    {
        $supplierRef = $this->resolveSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        $caseRef = (string) $this->request->getParam('caseRef', '');
        if ($caseRef === '') {
            return new JSONResponse(['items' => [], 'total' => 0]);
        }

        $items = $this->messages->getConversationHistory($caseRef, $supplierRef);
        return new JSONResponse(['items' => $items, 'total' => count($items)]);
    }//end messages()

    /**
     * Send a message on a case (supplier → operator direction).
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/leverancier-zaakportaal-11-messaging/tasks.md
     */
    public function sendMessage(): JSONResponse
    {
        $supplierRef = $this->resolveSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        $caseRef     = (string) $this->request->getParam('caseRef', '');
        $body        = trim((string) $this->request->getParam('body', ''));
        $attachments = (array) ($this->request->getParam('attachments', []));
        $sentBy      = (string) $this->request->getParam('sentBy', 'supplier');
        if ($caseRef === '') {
            return new JSONResponse(['error' => 'caseRef required'], Http::STATUS_BAD_REQUEST);
        }

        if ($body === '') {
            return new JSONResponse(['error' => 'message body required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $this->messages->validateAttachmentSet($attachments);
        } catch (\Throwable $e) {
            return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        $row = $this->messages->sendMessage($caseRef, $supplierRef, $body, $attachments, $sentBy);
        if ($row === null) {
            return new JSONResponse(['error' => 'send failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['ok' => true, 'message' => 'Bericht verstuurd', 'row' => $row]);
    }//end sendMessage()
}//end class
