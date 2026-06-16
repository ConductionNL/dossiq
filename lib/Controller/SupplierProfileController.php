<?php

/**
 * Procest Supplier Profile Controller
 *
 * Operator-side write endpoints for the leverancier-zaakportaal
 * profile / master-data-mutations surface (chain member 12).
 *
 * Address + contact-person updates apply immediately via
 * `SupplierMasterDataMutationService::updateAddress()` and
 * `updateContactPerson()`. IBAN changes are 4-eyes — they create a
 * Procest case via `requestIbanChange()` and do NOT modify the
 * supplier row; the actual write happens once the case closes (the
 * background `ProcessMasterDataMutationsJob` is deferred to chain
 * member 16). Accreditation submissions create a verification case
 * via `submitForVerification()`.
 *
 * Every endpoint is `#[NoAdminRequired]` because procurement officers
 * are NOT necessarily NC admins. The `supplierRef` is derived from the
 * SERVER-TRUSTED supplier session (`SupplierSessionService`, validated
 * from the portal bearer JWT) — never from a client-supplied parameter —
 * and every write FAILS CLOSED with 401 when no valid session is present.
 * The scope service then filters by that session `supplierRef`, so
 * cross-supplier IDOR is impossible.
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
 * @spec openspec/changes/leverancier-zaakportaal-12-master-data-mutations/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Middleware\SupplierUnauthorizedException;
use OCA\Procest\Service\SupplierMasterDataMutationService;
use OCA\Procest\Service\SupplierScopeService;
use OCA\Procest\Service\SupplierSessionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Internal-operator-facing endpoints for supplier profile updates.
 *
 * @psalm-suppress UnusedClass
 */
class SupplierProfileController extends Controller
{
    /**
     * Constructor.
     *
     * @param IRequest                            $request The request.
     * @param SupplierMasterDataMutationService   $mutation Master-data mutation service.
     * @param SupplierScopeService                $scope    Scope + masking helpers.
     * @param IUserSession                        $userSession Current NC user session.
     * @param SupplierSessionService              $session  Server-trusted supplier session resolver.
     */
    public function __construct(
        IRequest $request,
        private readonly SupplierMasterDataMutationService $mutation,
        private readonly SupplierScopeService $scope,
        private readonly IUserSession $userSession,
        private readonly SupplierSessionService $session,
    ) {
        parent::__construct(appName: Application::APP_ID, request: $request);
    }//end __construct()

    /**
     * Resolve the actor id from the NC session.
     *
     * @return string
     */
    private function actorId(): string
    {
        $user = $this->userSession->getUser();
        return $user === null ? 'system' : $user->getUID();
    }//end actorId()

    /**
     * Resolve the server-trusted supplier reference for the calling session.
     *
     * Fails CLOSED: sets `$error` to a 401 response when no valid supplier
     * session is present. Callers MUST short-circuit before any write.
     *
     * @param JSONResponse|null $error Set to a 401 response when no valid session.
     *
     * @return string The server-trusted supplier reference ('' when unauthenticated).
     */
    private function requireSupplierRef(?JSONResponse &$error): string
    {
        $error = null;
        try {
            return $this->session->requireSupplierRef();
        } catch (SupplierUnauthorizedException $e) {
            $error = new JSONResponse(['error' => 'Bearer token required'], Http::STATUS_UNAUTHORIZED);
            return '';
        }
    }//end requireSupplierRef()

    /**
     * Apply an address update immediately.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/leverancier-zaakportaal-12-master-data-mutations/tasks.md
     */
    public function updateAddress(): JSONResponse
    {
        $supplierRef = $this->requireSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        $address = (array) ($this->request->getParam('address', []));
        if ($address === []) {
            return new JSONResponse(['error' => 'address payload required'], Http::STATUS_BAD_REQUEST);
        }

        $row = $this->mutation->updateAddress($supplierRef, $address, $this->actorId());
        if ($row === null) {
            return new JSONResponse(['error' => 'update failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['ok' => true, 'message' => 'Adres bijgewerkt']);
    }//end updateAddress()

    /**
     * Apply a contact-person update immediately.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/leverancier-zaakportaal-12-master-data-mutations/tasks.md
     */
    public function updateContact(): JSONResponse
    {
        $supplierRef = $this->requireSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        $contact = trim((string) $this->request->getParam('contactPerson', ''));
        if ($contact === '') {
            return new JSONResponse(['error' => 'contactPerson required'], Http::STATUS_BAD_REQUEST);
        }

        $row = $this->mutation->updateContactPerson($supplierRef, $contact, $this->actorId());
        if ($row === null) {
            return new JSONResponse(['error' => 'update failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
        }

        return new JSONResponse(['ok' => true, 'message' => 'Contactpersoon bijgewerkt']);
    }//end updateContact()

    /**
     * Request an IBAN change. 4-eyes — creates a Procest case.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/leverancier-zaakportaal-12-master-data-mutations/tasks.md
     */
    public function requestIbanChange(): JSONResponse
    {
        $supplierRef = $this->requireSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        $iban = strtoupper(preg_replace('/\s+/', '', (string) $this->request->getParam('iban', '')) ?? '');
        if ($iban === '') {
            return new JSONResponse(['error' => 'iban required'], Http::STATUS_BAD_REQUEST);
        }

        $r = $this->mutation->requestIbanChange($supplierRef, $iban, $this->actorId());
        if ($r['ok'] === false) {
            return new JSONResponse(
                ['error' => $r['reason'] ?? 'IBAN change refused'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        return new JSONResponse([
            'ok'      => true,
            'caseRef' => $r['caseRef'] ?? '',
            'message' => 'Wijziging ingediend',
        ]);
    }//end requestIbanChange()

    /**
     * Submit accreditation documents for verification.
     *
     * @return JSONResponse
     *
     * @NoAdminRequired
     *
     * @psalm-suppress PossiblyUnusedMethod
     *
     * @spec openspec/changes/leverancier-zaakportaal-12-master-data-mutations/tasks.md
     */
    public function submitAccreditation(): JSONResponse
    {
        $supplierRef = $this->requireSupplierRef($err);
        if ($err !== null) {
            return $err;
        }

        $dataType    = (string) $this->request->getParam('dataType', 'accreditation');
        $attachments = (array) ($this->request->getParam('attachments', []));
        $kvkNumber   = (string) $this->request->getParam('kvkNumber', '');

        $r = $this->mutation->submitForVerification($supplierRef, $dataType, $attachments, $this->actorId(), $kvkNumber);
        if (($r['ok'] ?? false) === false) {
            return new JSONResponse(
                ['error' => $r['reason'] ?? 'Submission refused'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        return new JSONResponse(['ok' => true, 'caseRef' => $r['caseRef'] ?? '']);
    }//end submitAccreditation()
}//end class
