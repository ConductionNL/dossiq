<?php

/**
 * Procest Case Transfer Service
 *
 * Service for managing case ownership transfers between organizations.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for managing case ownership transfers between organizations.
 *
 * Supports initiating, accepting, and rejecting transfer requests
 * with full audit trail and notification support.
 */
class CaseTransferService
{
    /**
     * Constructor for the CaseTransferService.
     *
     * @param SettingsService    $settingsService The settings service
     * @param IAppManager        $appManager      The app manager
     * @param ContainerInterface $container       The DI container
     * @param LoggerInterface    $logger          The logger
     *
     * @return void
     */
    public function __construct(
        private SettingsService $settingsService,
        private IAppManager $appManager,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Initiate a case transfer to a target organization.
     *
     * @param string $caseId             The UUID of the case to transfer
     * @param string $sourceOrganization The source organization identifier
     * @param string $targetOrganization The UUID of the target partner organization
     * @param string $reason             The reason for transfer
     * @param string $requestedDate      The requested transfer date (ISO 8601)
     *
     * @return array The created transfer request data

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function initiateTransfer(
        string $caseId,
        string $sourceOrganization,
        string $targetOrganization,
        string $reason,
        string $requestedDate,
    ): array {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_transfer_schema');

        $transferData = [
            'caseId'             => $caseId,
            'sourceOrganization' => $sourceOrganization,
            'targetOrganization' => $targetOrganization,
            'reason'             => $reason,
            'requestedDate'      => $requestedDate,
            'status'             => 'pending',
        ];

        $result = $objectService->saveObject(
            (int) $register,
            (int) $schema,
            $transferData,
        );

        $this->logger->info(
            'Procest: Case transfer initiated',
            [
                'caseId'     => $caseId,
                'transferId' => $result->getUuid(),
                'target'     => $targetOrganization,
            ]
        );

        return $result->jsonSerialize();
    }//end initiateTransfer()

    /**
     * Accept a pending case transfer request.
     *
     * @param string $transferId The UUID of the transfer request
     *
     * @return array The updated transfer data

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function acceptTransfer(string $transferId): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_transfer_schema');

        $transfer     = $objectService->find($transferId, register: (int) $register, schema: (int) $schema);
        $transferData = is_object($transfer) ? $transfer->jsonSerialize() : (array) $transfer;

        if ($transferData['status'] !== 'pending') {
            return ['error' => 'Transfer is not in pending state'];
        }

        $transferData['status']      = 'accepted';
        $transferData['completedAt'] = (new \DateTime())->format('c');

        $result = $objectService->saveObject(
            (int) $register,
            (int) $schema,
            $transferData,
        );

        $this->logger->info(
            'Procest: Case transfer accepted',
            [
                'transferId' => $transferId,
                'caseId'     => $transferData['caseId'],
            ]
        );

        return $result->jsonSerialize();
    }//end acceptTransfer()

    /**
     * Reject a pending case transfer request.
     *
     * @param string $transferId      The UUID of the transfer request
     * @param string $rejectionReason The reason for rejection
     *
     * @return array The updated transfer data

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function rejectTransfer(string $transferId, string $rejectionReason): array
    {
        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['error' => 'OpenRegister is not available'];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue('case_transfer_schema');

        $transfer     = $objectService->find($transferId, register: (int) $register, schema: (int) $schema);
        $transferData = is_object($transfer) ? $transfer->jsonSerialize() : (array) $transfer;

        if ($transferData['status'] !== 'pending') {
            return ['error' => 'Transfer is not in pending state'];
        }

        $transferData['status']          = 'rejected';
        $transferData['rejectionReason'] = $rejectionReason;
        $transferData['completedAt']     = (new \DateTime())->format('c');

        $result = $objectService->saveObject(
            (int) $register,
            (int) $schema,
            $transferData,
        );

        $this->logger->info(
            'Procest: Case transfer rejected',
            [
                'transferId' => $transferId,
                'reason'     => $rejectionReason,
            ]
        );

        return $result->jsonSerialize();
    }//end rejectTransfer()

    /**
     * Get the OpenRegister ObjectService.
     *
     * @return \OCA\OpenRegister\Service\ObjectService|null The service or null
     */
    private function getObjectService(): ?\OCA\OpenRegister\Service\ObjectService
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
    }//end getObjectService()
}//end class
