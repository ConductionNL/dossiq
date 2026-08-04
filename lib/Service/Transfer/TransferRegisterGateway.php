<?php

/**
 * Procest OpenRegister gateway for the case-transfer surface.
 *
 * The single place the transfer surface reaches into OpenRegister. Each
 * resolution checks that the app is installed and — for the federation
 * collaborators — that the resolved service actually exposes the method the
 * caller will invoke, because procest runs against OpenRegister builds that
 * predate the federation leaf. Every failure resolves to null; nothing throws.
 *
 * Split out of CaseTransferService so the leaf-availability question is
 * answered in one place, leaving that service to state the transfer state
 * machine and its fail-closed policy over a uniform null.
 *
 * Note the deliberately loose `?object` return type: the OpenRegister classes
 * are NOT in this app's autoload map (OR is a separate app, resolved only
 * through the DI container at runtime), so a concrete return type here would
 * be unenforceable and would TypeError the moment a test passed a double.
 *
 * @category Service
 * @package  OCA\Procest\Service\Transfer
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

namespace OCA\Procest\Service\Transfer;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves the OpenRegister services the case-transfer surface depends on.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
class TransferRegisterGateway
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
     * Get the OpenRegister ObjectService.
     *
     * @return object|null The service or null
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md
     */
    public function objectService(): ?object
    {
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error(
                'Procest: Could not get ObjectService',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end objectService()

    /**
     * Resolve OpenRegister's FederationShareService. Returns null (fail
     * closed) when OR or its federation classes are unavailable.
     *
     * @return object|null The OR FederationShareService, or null
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md
     */
    public function federationShareService(): ?object
    {
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            return null;
        }

        try {
            $service = $this->container->get('OCA\OpenRegister\Service\FederationShareService');
            if (method_exists($service, 'createOutgoingShare') === false) {
                return null;
            }

            return $service;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: Could not get OR FederationShareService',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end federationShareService()

    /**
     * Resolve OpenRegister's FederatedShareMapper — used only to resolve a
     * scoped bearer token to its share (`findByToken`), for the remote
     * accept/reject endpoint. Returns null (fail closed) when unavailable.
     *
     * @return object|null The OR FederatedShareMapper, or null
     *
     * @spec openspec/specs/federated-case-collaboration/spec.md
     */
    public function federatedShareMapper(): ?object
    {
        if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
            return null;
        }

        try {
            $mapper = $this->container->get('OCA\OpenRegister\Db\FederatedShareMapper');
            if (method_exists($mapper, 'findByToken') === false) {
                return null;
            }

            return $mapper;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Procest: Could not get OR FederatedShareMapper',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end federatedShareMapper()
}//end class
