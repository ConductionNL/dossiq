<?php

/**
 * Procest Supplier Session Service
 *
 * Resolves the authenticated supplier portal session from the request bearer
 * token and returns the SERVER-TRUSTED supplier reference + role. This is the
 * single authorization anchor for the leverancier-zaakportaal surface: portal
 * controllers MUST derive their `supplierRef` from this resolver, never from a
 * client-supplied request parameter, so a caller can only ever read/write their
 * own supplier's objects (IDOR-safe, OWASP A01:2021 / ADR-005 Rule 3).
 *
 * The resolver fails CLOSED: when no valid bearer token is present it throws
 * {@see SupplierUnauthorizedException}, which the controller surfaces as HTTP
 * 401. There is no fall-open path.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @spec openspec/changes/procest-security-hardening/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\Middleware\SupplierUnauthorizedException;
use OCP\IRequest;

/**
 * Server-trusted supplier-session resolver for the leverancier-zaakportaal.
 *
 * @psalm-suppress UnusedClass
 */
class SupplierSessionService
{
    /**
     * Constructor.
     *
     * @param IRequest             $request The request (carries the Authorization header + middleware-injected refs).
     * @param SupplierScopeService $scope   Resolves + validates the supplier bearer JWT.
     */
    public function __construct(
        private readonly IRequest $request,
        private readonly SupplierScopeService $scope,
    ) {
    }//end __construct()

    /**
     * Resolve the current authenticated supplier session.
     *
     * Prefers the server-trusted refs injected by {@see \OCA\Procest\Middleware\SupplierAuthMiddleware}
     * (set from the validated JWT before the controller body runs); falls back
     * to validating the Authorization bearer directly so the resolver is correct
     * even on a controller not yet covered by the middleware.
     *
     * @return array{supplierRef:string, supplierUserId:string, role:string}|null
     *         The supplier session, or null when no valid session is present.
     *
     * @spec openspec/changes/procest-security-hardening/specs/security-hardening/spec.md
     */
    public function currentSupplier(): ?array
    {
        $injectedRef = (string) $this->request->getParam('_supplierRef', '');
        if ($injectedRef !== '') {
            return [
                'supplierRef'    => $injectedRef,
                'supplierUserId' => (string) $this->request->getParam('_supplierUserId', ''),
                'role'           => (string) $this->request->getParam('_supplierRole', 'read_only'),
            ];
        }

        $resolved = $this->scope->resolveFromBearer((string) $this->request->getHeader('Authorization'));
        if ($resolved === null || ($resolved['supplierRef'] ?? '') === '') {
            return null;
        }

        return $resolved;
    }//end currentSupplier()

    /**
     * Require an authenticated supplier session and return its server-trusted
     * `supplierRef`. Fails CLOSED — throws when no valid session is present.
     *
     * @return string The server-trusted supplier reference.
     *
     * @throws SupplierUnauthorizedException When no valid supplier session is present.
     *
     * @spec openspec/changes/procest-security-hardening/specs/security-hardening/spec.md
     */
    public function requireSupplierRef(): string
    {
        $supplier = $this->currentSupplier();
        if ($supplier === null) {
            throw new SupplierUnauthorizedException(message: 'Bearer token required', code: 401);
        }

        return (string) $supplier['supplierRef'];
    }//end requireSupplierRef()

    /**
     * Require an authenticated supplier session and return its server-trusted
     * role. Fails CLOSED.
     *
     * @return string The server-trusted supplier role (default `read_only`).
     *
     * @throws SupplierUnauthorizedException When no valid supplier session is present.
     *
     * @spec openspec/changes/procest-security-hardening/specs/security-hardening/spec.md
     */
    public function requireSupplierRole(): string
    {
        $supplier = $this->currentSupplier();
        if ($supplier === null) {
            throw new SupplierUnauthorizedException(message: 'Bearer token required', code: 401);
        }

        $role = (string) ($supplier['role'] ?? 'read_only');
        if ($role === '') {
            $role = 'read_only';
        }

        return $role;
    }//end requireSupplierRole()
}//end class
