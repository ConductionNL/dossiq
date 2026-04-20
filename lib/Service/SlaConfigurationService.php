<?php

/**
 * Procest SLA Configuration Service
 *
 * Service for managing SLA (Service Level Agreement) configurations
 * per zaaktype and process step.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;

/**
 * Service for SLA configuration management.
 *
 * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-2
 */
class SlaConfigurationService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service
     * @param LoggerInterface $logger          Logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get SLA configuration for a specific zaaktype.
     *
     * @param string $caseTypeId The case type UUID
     *
     * @return array<string, mixed> SLA configuration
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-2
     */
    public function getSlForCaseType(string $caseTypeId): array
    {
        $this->logger->debug('Fetching SLA configuration for case type: '.$caseTypeId);

        // Placeholder implementation.
        // In production, would fetch from OpenRegister SLA configuration schema.
        return [
            'caseTypeId'    => $caseTypeId,
            'streeftermijn' => 30,
            // Target time in days.
            'fatalTermijn'  => 60,
            // Deadline in days.
            'startDate'     => date('Y-m-d'),
            'endDate'       => date('Y-m-d', strtotime('+1 year')),
            'processSteps'  => [],
        ];
    }//end getSlForCaseType()

    /**
     * Get SLA configuration for a specific process step.
     *
     * @param string $caseTypeId    The case type UUID
     * @param string $processStepId The process step UUID
     *
     * @return array<string, mixed> Step-specific SLA configuration
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-2
     */
    public function getSlAforStep(string $caseTypeId, string $processStepId): array
    {
        $this->logger->debug(
            'Fetching SLA configuration for step: '.$processStepId
            .' in case type: '.$caseTypeId
        );

        // Placeholder implementation.
        return [
            'caseTypeId'    => $caseTypeId,
            'processStepId' => $processStepId,
            'streeftermijn' => 10,
            'fatalTermijn'  => 15,
            'description'   => 'Step-specific SLA targets',
        ];
    }//end getSlAforStep()

    /**
     * Get all SLA configurations.
     *
     * @return array<int, array<string, mixed>> List of SLA configurations
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-2
     */
    public function getAllConfigurations(): array
    {
        $this->logger->debug('Fetching all SLA configurations');

        // Placeholder: would fetch from OpenRegister in production.
        return [
            [
                'caseTypeId'    => 'example-case-type-1',
                'streeftermijn' => 30,
                'fatalTermijn'  => 60,
            ],
            [
                'caseTypeId'    => 'example-case-type-2',
                'streeftermijn' => 14,
                'fatalTermijn'  => 30,
            ],
        ];
    }//end getAllConfigurations()

    /**
     * Create or update SLA configuration for a case type.
     *
     * @param string               $caseTypeId The case type UUID
     * @param array<string, mixed> $config     The SLA configuration data
     *
     * @return array<string, mixed> The saved configuration
     *
     * @throws \RuntimeException If configuration cannot be saved
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-2
     */
    public function saveConfiguration(string $caseTypeId, array $config): array
    {
        try {
            $this->logger->info(
                'Saving SLA configuration for case type: '.$caseTypeId,
                ['config' => $config]
            );

            // In production, would save to OpenRegister.
            $savedConfig = array_merge(
                [
                    'caseTypeId' => $caseTypeId,
                    'createdAt'  => date('Y-m-d\TH:i:s'),
                    'updatedAt'  => date('Y-m-d\TH:i:s'),
                ],
                $config
            );

            return $savedConfig;
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to save SLA configuration: '.$e->getMessage()
            );
            throw new \RuntimeException('Could not save SLA configuration');
        }//end try
    }//end saveConfiguration()

    /**
     * Get default SLA configuration.
     *
     * @return array<string, mixed> Default SLA values
     *
     * @spec openspec/changes/doorlooptijd-dashboard/tasks.md#task-2
     */
    public function getDefaultConfiguration(): array
    {
        return [
            'streeftermijn'    => 30,
        // 30 days default target
            'fatalTermijn'     => 60,
        // 60 days default deadline
            'description'      => 'Default SLA configuration',
            'suspensionStatus' => [
                'suspended',
                'on_hold',
            ],
        ];
    }//end getDefaultConfiguration()
}//end class
