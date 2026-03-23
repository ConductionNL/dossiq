<?php

/**
 * Procest Seed Data Service
 *
 * Service for seeding pre-defined case types, status types, role types,
 * and workflow templates into OpenRegister.
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
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for seeding bezwaar/beroep case types and related configuration.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — needs OpenRegister service access
 */
class SeedDataService
{
    /**
     * Constructor for the SeedDataService.
     *
     * @param IAppConfig         $appConfig The app configuration service
     * @param ContainerInterface $container The DI container
     * @param LoggerInterface    $logger    The logger interface
     *
     * @return void
     */
    public function __construct(
        private IAppConfig $appConfig,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Seed the bezwaar and beroep case types with all related objects.
     *
     * This method is idempotent — it checks for existing objects by identifier
     * before creating new ones.
     *
     * @return array Result summary with counts of created and skipped objects
     */
    public function seedBezwaarBeroepData(): array
    {
        $seedPath = __DIR__.'/../Settings/bezwaar_seed_data.json';
        if (file_exists($seedPath) === false) {
            $this->logger->error('Procest: Seed data file not found at '.$seedPath);
            return ['success' => false, 'message' => 'Seed data file not found'];
        }

        $seedContent = file_get_contents($seedPath);
        $seedData    = json_decode($seedContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Procest: Invalid JSON in seed data file');
            return ['success' => false, 'message' => 'Invalid JSON in seed data file'];
        }

        $objectService = $this->getObjectService();
        if ($objectService === null) {
            return ['success' => false, 'message' => 'ObjectService not available'];
        }

        $registerId       = $this->getConfigValue(key: 'register');
        $caseTypeSchema   = $this->getConfigValue(key: 'case_type_schema');
        $statusTypeSchema = $this->getConfigValue(key: 'status_type_schema');
        $roleTypeSchema   = $this->getConfigValue(key: 'role_type_schema');
        $workflowSchema   = $this->getConfigValue(key: 'workflow_template_schema');

        if ($registerId === '' || $caseTypeSchema === '') {
            $this->logger->warning('Procest: Register or case type schema not configured, skipping seed');
            return ['success' => false, 'message' => 'Register or schemas not configured'];
        }

        $summary = [
            'success'     => true,
            'caseTypes'   => 0,
            'statusTypes' => 0,
            'roleTypes'   => 0,
            'workflows'   => 0,
            'skipped'     => 0,
        ];

        foreach (($seedData['caseTypes'] ?? []) as $caseTypeData) {
            $result = $this->seedCaseType(
                objectService: $objectService,
                caseTypeData: $caseTypeData,
                registerId: $registerId,
                caseTypeSchema: $caseTypeSchema,
                statusTypeSchema: $statusTypeSchema,
                roleTypeSchema: $roleTypeSchema,
                workflowSchema: $workflowSchema,
            );

            $summary['caseTypes']   += $result['caseTypes'];
            $summary['statusTypes'] += $result['statusTypes'];
            $summary['roleTypes']   += $result['roleTypes'];
            $summary['workflows']   += $result['workflows'];
            $summary['skipped']     += $result['skipped'];
        }

        $this->logger->info('Procest: Seed data complete', $summary);

        return $summary;
    }//end seedBezwaarBeroepData()

    /**
     * Seed a single case type with its status types, role types, and workflow.
     *
     * @param object $objectService    The OpenRegister ObjectService
     * @param array  $caseTypeData     The case type seed data
     * @param string $registerId       The register UUID
     * @param string $caseTypeSchema   The case type schema UUID
     * @param string $statusTypeSchema The status type schema UUID
     * @param string $roleTypeSchema   The role type schema UUID
     * @param string $workflowSchema   The workflow template schema UUID
     *
     * @return array Counts of created and skipped objects
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList) — all schema IDs are needed
     */
    private function seedCaseType(
        object $objectService,
        array $caseTypeData,
        string $registerId,
        string $caseTypeSchema,
        string $statusTypeSchema,
        string $roleTypeSchema,
        string $workflowSchema,
    ): array {
        $counts = [
            'caseTypes'   => 0,
            'statusTypes' => 0,
            'roleTypes'   => 0,
            'workflows'   => 0,
            'skipped'     => 0,
        ];

        $identifier = ($caseTypeData['identifier'] ?? '');

        // Check if case type already exists by identifier.
        $existing = $this->findByFilter(
            objectService: $objectService,
            registerId: $registerId,
            schemaId: $caseTypeSchema,
            filters: ['identifier' => $identifier],
        );

        if ($existing !== null) {
            $this->logger->info(
                'Procest: Case type already exists, skipping seed',
                ['identifier' => $identifier]
            );
            $counts['skipped']++;
            return $counts;
        }

        // Extract nested data before creating the case type.
        $statusTypesData = ($caseTypeData['statusTypes'] ?? []);
        $roleTypesData   = ($caseTypeData['roleTypes'] ?? []);
        $workflowData    = ($caseTypeData['workflowTemplate'] ?? null);

        unset(
            $caseTypeData['statusTypes'],
            $caseTypeData['roleTypes'],
            $caseTypeData['workflowTemplate']
        );

        // Create the case type.
        $caseType = $this->createObject(
            objectService: $objectService,
            registerId: $registerId,
            schemaId: $caseTypeSchema,
            data: $caseTypeData,
        );

        if ($caseType === null) {
            $this->logger->error(
                'Procest: Failed to create case type',
                ['identifier' => $identifier]
            );
            return $counts;
        }

        $caseTypeId = $this->getObjectId(object: $caseType);
        $counts['caseTypes']++;

        $this->logger->info(
            'Procest: Created case type',
            ['identifier' => $identifier, 'id' => $caseTypeId]
        );

        // Create status types and build a name-to-ID map.
        $statusNameToId = [];
        foreach ($statusTypesData as $statusData) {
            $statusData['caseType'] = $caseTypeId;
            $statusObj = $this->createObject(
                objectService: $objectService,
                registerId: $registerId,
                schemaId: $statusTypeSchema,
                data: $statusData,
            );

            if ($statusObj !== null) {
                $statusId = $this->getObjectId(object: $statusObj);
                $statusNameToId[$statusData['name']] = $statusId;
                $counts['statusTypes']++;
            }
        }

        // Create role types and build a name-to-ID map.
        $roleNameToId = [];
        if ($roleTypeSchema !== '') {
            foreach ($roleTypesData as $roleData) {
                $roleData['caseType'] = $caseTypeId;
                $roleObj = $this->createObject(
                    objectService: $objectService,
                    registerId: $registerId,
                    schemaId: $roleTypeSchema,
                    data: $roleData,
                );

                if ($roleObj !== null) {
                    $roleId = $this->getObjectId(object: $roleObj);
                    $roleNameToId[$roleData['name']] = $roleId;
                    $counts['roleTypes']++;
                }
            }
        }

        // Create workflow template with resolved status/role references.
        if ($workflowData !== null && $workflowSchema !== '') {
            $resolvedWorkflow = $this->resolveWorkflowReferences(
                workflowData: $workflowData,
                statusNameMap: $statusNameToId,
                roleNameMap: $roleNameToId,
                caseTypeId: $caseTypeId,
            );

            $workflowObj = $this->createObject(
                objectService: $objectService,
                registerId: $registerId,
                schemaId: $workflowSchema,
                data: $resolvedWorkflow,
            );

            if ($workflowObj !== null) {
                $counts['workflows']++;
            }
        }

        return $counts;
    }//end seedCaseType()

    /**
     * Resolve workflow step and transition references from names to UUIDs.
     *
     * @param array  $workflowData  The raw workflow template data
     * @param array  $statusNameMap Status name to UUID mapping
     * @param array  $roleNameMap   Role name to UUID mapping
     * @param string $caseTypeId    The case type UUID
     *
     * @return array The workflow data with resolved references
     */
    private function resolveWorkflowReferences(
        array $workflowData,
        array $statusNameMap,
        array $roleNameMap,
        string $caseTypeId,
    ): array {
        $workflowData['caseType'] = $caseTypeId;

        // Resolve steps: map statusName to status UUID.
        $resolvedSteps = [];
        foreach (($workflowData['steps'] ?? []) as $step) {
            $statusName = ($step['statusName'] ?? '');
            unset($step['statusName']);

            $step['id']     = $this->generateUUID();
            $step['status'] = ($statusNameMap[$statusName] ?? '');

            $resolvedSteps[] = $step;
        }

        $workflowData['steps'] = json_encode($resolvedSteps);

        // Resolve transitions: map statusName to UUID, roleName to UUID.
        $resolvedTransitions = [];
        foreach (($workflowData['transitions'] ?? []) as $transition) {
            $fromName = ($transition['fromStatusName'] ?? '');
            $toName   = ($transition['toStatusName'] ?? '');
            unset($transition['fromStatusName'], $transition['toStatusName']);

            $transition['id'] = $this->generateUUID();

            // Handle wildcard "*" for "any active status".
            if ($fromName === '*') {
                $transition['fromStatus'] = '*';
            } else {
                $transition['fromStatus'] = ($statusNameMap[$fromName] ?? '');
            }

            $transition['toStatus'] = ($statusNameMap[$toName] ?? '');

            // Resolve role guards.
            $resolvedGuards = [];
            foreach (($transition['guards'] ?? []) as $guard) {
                if ($guard['type'] === 'roleGuard' && isset($guard['config']['roleName']) === true) {
                    $roleName = $guard['config']['roleName'];
                    $guard['config']['roleId'] = ($roleNameMap[$roleName] ?? '');
                }

                $resolvedGuards[] = $guard;
            }

            $transition['guards'] = $resolvedGuards;

            // Resolve automatic action role references.
            $resolvedActions = [];
            foreach (($transition['automaticActions'] ?? []) as $action) {
                if (isset($action['config']['roleName']) === true) {
                    $roleName = $action['config']['roleName'];
                    $action['config']['roleId'] = ($roleNameMap[$roleName] ?? '');
                }

                $resolvedActions[] = $action;
            }

            $transition['automaticActions'] = $resolvedActions;

            $resolvedTransitions[] = $transition;
        }//end foreach

        $workflowData['transitions'] = json_encode($resolvedTransitions);

        // Remove raw array data that was already JSON-encoded.
        unset($workflowData['steps_raw'], $workflowData['transitions_raw']);

        return $workflowData;
    }//end resolveWorkflowReferences()

    /**
     * Create an object in OpenRegister via ObjectService.
     *
     * @param object $objectService The ObjectService instance
     * @param string $registerId    The register UUID
     * @param string $schemaId      The schema UUID
     * @param array  $data          The object data
     *
     * @return object|null The created object or null on failure
     */
    private function createObject(
        object $objectService,
        string $registerId,
        string $schemaId,
        array $data,
    ): ?object {
        try {
            return $objectService->saveObject(
                register: $registerId,
                schema: $schemaId,
                object: $data,
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Procest: Failed to create seed object',
                [
                    'schema'    => $schemaId,
                    'exception' => $e->getMessage(),
                ]
            );
            return null;
        }
    }//end createObject()

    /**
     * Find an existing object by filter criteria.
     *
     * @param object $objectService The ObjectService instance
     * @param string $registerId    The register UUID
     * @param string $schemaId      The schema UUID
     * @param array  $filters       Filter criteria
     *
     * @return object|null The found object or null
     */
    private function findByFilter(
        object $objectService,
        string $registerId,
        string $schemaId,
        array $filters,
    ): ?object {
        try {
            $results = $objectService->getObjects(
                register: $registerId,
                schema: $schemaId,
                filters: $filters,
                limit: 1,
            );

            if (is_array($results) === true && count($results) > 0) {
                return $results[0];
            }

            // Handle paginated result format.
            if (is_array($results) === true
                && isset($results['results']) === true
                && count($results['results']) > 0
            ) {
                return $results['results'][0];
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->debug(
                'Procest: Could not search for existing object',
                ['exception' => $e->getMessage()]
            );
            return null;
        }//end try
    }//end findByFilter()

    /**
     * Get the ObjectService from OpenRegister via the DI container.
     *
     * @return object|null The ObjectService or null if unavailable
     */
    private function getObjectService(): ?object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Exception $e) {
            $this->logger->error(
                'Procest: Could not access ObjectService',
                ['exception' => $e->getMessage()]
            );
            return null;
        }
    }//end getObjectService()

    /**
     * Get a configuration value from the app config.
     *
     * @param string $key The configuration key
     *
     * @return string The configuration value
     */
    private function getConfigValue(string $key): string
    {
        return $this->appConfig->getValueString(Application::APP_ID, $key, '');
    }//end getConfigValue()

    /**
     * Get the ID from an OpenRegister object.
     *
     * @param object $object The OpenRegister object
     *
     * @return string The object ID
     */
    private function getObjectId(object $object): string
    {
        if (method_exists($object, 'getId') === true) {
            return (string) $object->getId();
        }

        if (method_exists($object, 'getUuid') === true) {
            return (string) $object->getUuid();
        }

        return '';
    }//end getObjectId()

    /**
     * Generate a UUID v4 string.
     *
     * @return string A new UUID
     */
    private function generateUUID(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($data), 4)
        );
    }//end generateUUID()
}//end class
