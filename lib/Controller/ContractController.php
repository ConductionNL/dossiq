<?php

/**
 * Procest Contract Controller
 *
 * Supplier-scoped REST surface for the leverancier-zaakportaal contract module
 * (chain member 09). Serves the contract list + detail and processes
 * supplier-initiated renewal requests, which open a Procest
 * `leverancier-contractverlenging-verzoek` case via
 * {@see ContractRenewalService}.
 *
 * Every endpoint is `#[NoAdminRequired]` because procurement officers and
 * supplier users are NOT necessarily NC admins (the SecurityMiddleware admin
 * default is too strict). The `supplierRef` and role are derived from the
 * SERVER-TRUSTED supplier session (`SupplierSessionService`, validated from the
 * portal bearer JWT) — never from a client-supplied parameter — and every
 * endpoint FAILS CLOSED with 401 when no valid session is present. Cross-supplier
 * IDOR is then prevented per-object: list reads go through
 * `SupplierScopeService::listSupplierObjects()` (filtered by the session
 * `supplierRef`); detail + renewal re-resolve the object inside the supplier's
 * own scope and FAIL CLOSED with 403 when the requested id is not owned by the
 * caller. Renewal additionally enforces the contracts/admin role gate using the
 * session role, so a read_only supplier cannot self-elevate.
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
 * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/specs/supplier-portal/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Middleware\SupplierUnauthorizedException;
use OCA\Procest\Service\ContractRenewalService;
use OCA\Procest\Service\SupplierScopeService;
use OCA\Procest\Service\SupplierSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Supplier-scoped contract list/detail + renewal-request endpoints.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/specs/supplier-portal/spec.md
 */
class ContractController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest               $request The request.
     * @param SupplierScopeService   $scope   Scope + per-object access guard.
     * @param ContractRenewalService $renewal Contract renewal orchestration.
     * @param SupplierSessionService $session Server-trusted supplier session resolver.
     */
    public function __construct(
        IRequest $request,
        private readonly SupplierScopeService $scope,
        private readonly ContractRenewalService $renewal,
        private readonly SupplierSessionService $session,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Resolve the server-trusted supplier reference for the calling session.
     *
     * Fails CLOSED: sets `$error` to a 401 response when no valid supplier
     * session is present. Callers MUST short-circuit before any object access.
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
     * Contract list for the calling supplier.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @no-admin-idor-exempt Collection endpoint — returns ONLY the caller's own
     *   rows via SupplierScopeService::listSupplierObjects() (filtered by
     *   supplierRef). No per-object id is accepted, so cross-supplier IDOR is
     *   structurally impossible; an empty/foreign supplierRef yields an empty set.
     *
     * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/specs/supplier-portal/spec.md
     */
    #[NoAdminRequired]
    public function index(): JSONResponse
    {
        $supplierRef = $this->resolveSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        $contracts = $this->scope->listSupplierObjects($supplierRef, 'supplierContract');
        $nowTs     = time();
        $rows      = array_map(
                function (array $c) use ($nowTs): array {
                    $c['daysUntilExpiry']   = $this->renewal->daysUntilExpiry((string) ($c['endDate'] ?? ''), $nowTs);
                    $c['renewalWindowOpen'] = $this->renewal->isWithinRenewalWindow($c, $nowTs);
                    return $c;
                },
                $contracts
                );

        return new JSONResponse(['items' => $rows, 'total' => count($rows)]);
    }//end index()

    /**
     * Contract detail — scoped to the calling supplier (fails closed on IDOR).
     *
     * @param string $id Contract UUID.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/specs/supplier-portal/spec.md
     */
    #[NoAdminRequired]
    public function show(string $id): JSONResponse
    {
        $supplierRef = $this->resolveSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        // Per-object scope guard (fail closed). findOwnedContract re-resolves the
        // id inside the caller's own supplier scope and re-asserts ownership via
        // validateSupplierAccess(); a foreign/unknown id yields null → 403, so a
        // supplier can never read another supplier's contract (no IDOR).
        $contract = $this->findOwnedContract(id: $id, supplierRef: $supplierRef);
        if ($contract === null) {
            return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }

        $nowTs = time();
        $contract['daysUntilExpiry']   = $this->renewal->daysUntilExpiry((string) ($contract['endDate'] ?? ''), $nowTs);
        $contract['renewalWindowOpen'] = $this->renewal->isWithinRenewalWindow($contract, $nowTs);

        return new JSONResponse($contract);
    }//end show()

    /**
     * Request renewal of a contract — opens a Procest case, delegates the
     * approval decision to decidesk (ADR-019), and notifies the account manager.
     * Restricted to contracts/admin roles; cross-supplier requests are rejected
     * with 403. Returns both caseRef and decisionRef in the response envelope.
     *
     * @param string $id Contract UUID.
     *
     * @return JSONResponse
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/leverancier-zaakportaal-09-contract-backend/specs/supplier-portal/spec.md
     * @spec openspec/changes/procest-delegate-contract-decision/specs/contract-decision-delegation/spec.md#req-pdcd-001
     */
    #[NoAdminRequired]
    public function requestRenewal(string $id): JSONResponse
    {
        $supplierRef = $this->resolveSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        // Role gate — only contracts/admin may request renewal (member 04 scope).
        // The role is read from the SERVER-TRUSTED session, never the client,
        // so a read_only supplier cannot self-elevate by passing role=admin.
        $role = $this->session->requireSupplierRole();
        if ($this->renewal->canRequestRenewal($role) === false) {
            return new JSONResponse(['error' => 'insufficient role'], Http::STATUS_FORBIDDEN);
        }

        // Per-object scope guard — re-resolve inside the caller's own supplier
        // scope. A contract id belonging to another supplier resolves to null
        // here, so we return 403 (cross-supplier access denied / IDOR fail-closed).
        $contract = $this->findOwnedContract(id: $id, supplierRef: $supplierRef);
        if ($contract === null) {
            return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
        }

        // Only manual-request contracts inside the 90-day window can be renewed.
        if ((string) ($contract['renewalOption'] ?? '') !== 'manual_request') {
            return new JSONResponse(['error' => 'contract is not manually renewable'], Http::STATUS_BAD_REQUEST);
        }

        if ($this->renewal->isWithinRenewalWindow($contract, time()) === false) {
            return new JSONResponse(['error' => 'contract is not within the renewal window'], Http::STATUS_BAD_REQUEST);
        }

        $actor  = (string) $this->request->getParam('actor', $supplierRef);
        $result = $this->renewal->requestRenewal($contract, $actor);
        if (($result['ok'] ?? false) === false) {
            return new JSONResponse(['error' => ($result['reason'] ?? 'renewal request failed')], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        // REQ-PDCD-001: return decisionRef so the caller can track the decidesk Decision.
        return new JSONResponse([
            'ok'          => true,
            'caseRef'     => (string) ($result['caseRef'] ?? ''),
            'decisionRef' => (string) ($result['decisionRef'] ?? ''),
        ]);
    }//end requestRenewal()

    /**
     * Resolve a contract by id within the caller's supplier scope.
     *
     * Returns null when the id is not owned by `$supplierRef` — the basis for
     * the IDOR fail-closed behaviour on detail/renewal.
     *
     * @param string $id          Contract UUID.
     * @param string $supplierRef Calling supplier.
     *
     * @return array<string,mixed>|null
     */
    private function findOwnedContract(string $id, string $supplierRef): ?array
    {
        $contracts = $this->scope->listSupplierObjects($supplierRef, 'supplierContract');
        foreach ($contracts as $c) {
            if ((string) ($c['uuid'] ?? $c['id'] ?? '') === $id) {
                // Defence-in-depth: the list is already scope-filtered, but
                // re-assert ownership before returning.
                if ($this->scope->validateSupplierAccess($c, $supplierRef) === true) {
                    return $c;
                }
            }
        }

        return null;
    }//end findOwnedContract()
}//end class
