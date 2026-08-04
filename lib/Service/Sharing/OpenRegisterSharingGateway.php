<?php

/**
 * Procest OpenRegister gateway for the case-sharing surface.
 *
 * The single place the sharing surface reaches into OpenRegister. Every
 * resolution is guarded twice — the app must be installed, and the resolved
 * service must actually expose the methods the caller will invoke — because
 * procest runs against OpenRegister builds that predate the shares and
 * federation leaves. A missing leaf resolves to null; it never throws.
 *
 * Split out of CaseSharingService so the "is the leaf there?" question is
 * answered in one place for all three sharing modes (token links, partner
 * hand-off, OCM federation), and so each mode's service can state its own
 * fail-open/fail-closed policy over a uniform null.
 *
 * `toArray()` lives here for the same reason: OpenRegister hands back either a
 * plain array or an ObjectEntity depending on the call path, and every caller
 * needs the same normalisation before reading fields.
 *
 * @category Service
 * @package  OCA\Procest\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Sharing;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the OpenRegister services the case-sharing surface depends on.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
class OpenRegisterSharingGateway
{
    /**
     * Constructor.
     *
     * @param IAppManager        $appManager The app manager
     * @param ContainerInterface $container  The DI container
     * @param LoggerInterface    $logger     The logger
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the ObjectService from the DI container.
     *
     * @return object|null The ObjectService, or null when OpenRegister is unavailable
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md
     */
    public function objectService(): ?object
    {
        if ($this->appManager->isInstalled('openregister') === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CaseSharingService: ObjectService unavailable',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end objectService()

    /**
     * Resolve OpenRegister's CaseTokenService — the public "track your
     * case" token-link surface of the shares integration leaf (ADR-022).
     *
     * The leaf owns token generation (256-bit non-guessable handle),
     * expiry, revocation, and the RBAC-respecting public resolve path;
     * procest mints no share tokens of its own.
     *
     * @return object|null The OR CaseTokenService, or null when OR is
     *                     unavailable / pre-foundation build.
     *
     * @spec openspec/changes/migrate-public-share-to-shares-leaf/tasks.md#P1.2
     */
    public function caseTokenService(): ?object
    {
        if ($this->appManager->isInstalled('openregister') === false) {
            return null;
        }

        try {
            $service = $this->container->get('OCA\OpenRegister\Service\CaseTokenService');
            if (method_exists($service, 'mint') === false) {
                return null;
            }

            return $service;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CaseSharingService: OR CaseTokenService unavailable (shares leaf not present)',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end caseTokenService()

    /**
     * Resolve OpenRegister's FederationShareService — the leaf that owns
     * OCM token minting, transport and lifecycle status. Returns null (fail
     * closed for federation callers) when OR or its federation classes are
     * unavailable.
     *
     * @return object|null The OR FederationShareService, or null
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md
     */
    public function federationShareService(): ?object
    {
        if ($this->appManager->isInstalled('openregister') === false) {
            return null;
        }

        try {
            $service = $this->container->get('OCA\OpenRegister\Service\FederationShareService');
            if (method_exists($service, 'createOutgoingShare') === false || method_exists($service, 'setStatus') === false) {
                return null;
            }

            return $service;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'CaseSharingService: OR FederationShareService unavailable (federation leaf not present)',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end federationShareService()

    /**
     * Normalize an OpenRegister return value (array or ObjectEntity) to an array.
     *
     * @param mixed $value The value returned by the ObjectService
     *
     * @return array<string, mixed> The value as a plain array
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md
     */
    public function toArray(mixed $value): array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            return (array) $value->jsonSerialize();
        }

        return [];
    }//end toArray()
}//end class
