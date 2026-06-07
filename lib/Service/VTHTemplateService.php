<?php

/**
 * Procest VTH Template Service
 *
 * Loads VTH zaaktype templates from lib/Settings/templates/vth-*.json and
 * activates them as case type configurations in OpenRegister. Parallels the
 * WOO template-library pattern from TemplateLibraryService.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-2
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for loading and activating VTH zaaktype templates.
 *
 * VTH templates live in lib/Settings/templates/vth-*.json. Each template
 * defines a complete case type configuration (status types, document types,
 * role types, property definitions). Activation is idempotent: re-running
 * on an existing case type updates it in-place rather than duplicating it.
 *
 * @spec openspec/changes/vth-module/tasks.md#task-2
 */
class VTHTemplateService
{

    /**
     * Directory containing VTH template JSON files.
     */
    private const TEMPLATES_DIR = __DIR__.'/../Settings/templates';

    /**
     * Prefix for VTH template files.
     */
    private const VTH_PREFIX = 'vth-';

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings service for register/schema refs
     * @param LoggerInterface $logger          Logger
     *
     * @spec openspec/changes/vth-module/tasks.md#task-2
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List all available VTH templates.
     *
     * Scans the templates directory for vth-*.json files and returns their
     * metadata without loading the full template body.
     *
     * @return array<int, array<string, mixed>> List of template metadata
     *
     * @spec openspec/changes/vth-module/tasks.md#task-2
     */
    public function listTemplates(): array
    {
        $templates = [];
        $dir       = self::TEMPLATES_DIR;

        if (is_dir($dir) === false) {
            return $templates;
        }

        $files = glob($dir.'/'.self::VTH_PREFIX.'*.json');
        if ($files === false) {
            return $templates;
        }

        foreach ($files as $file) {
            $data = $this->loadFile(path: $file);
            if ($data === null) {
                continue;
            }

            $templates[] = [
                'id'          => $data['id'] ?? basename(path: $file, suffix: '.json'),
                'title'       => $data['title'] ?? '',
                'description' => $data['description'] ?? '',
                'category'    => $data['category'] ?? 'vth',
                'version'     => $data['version'] ?? '1.0.0',
            ];
        }

        return $templates;
    }//end listTemplates()

    /**
     * Activate a VTH template by its slug identifier.
     *
     * Loads the template JSON, creates or updates the case type and all
     * associated sub-objects (status types, role types, document types,
     * property definitions) in OpenRegister. Activation is idempotent.
     *
     * @param string $slug Template slug (e.g. 'vth-omgevingsvergunning')
     *
     * @return array<string, mixed> Activation result with caseTypeId and counts
     *
     * @throws RuntimeException If template not found or OpenRegister unavailable
     *
     * @spec openspec/changes/vth-module/tasks.md#task-2
     */
    public function activateTemplate(string $slug): array
    {
        $file = self::TEMPLATES_DIR.'/'.ltrim(string: $slug, characters: '/').'.json';
        if (file_exists($file) === false) {
            throw new RuntimeException('VTH template not found: '.$slug);
        }

        $template = $this->loadFile(path: $file);
        if ($template === null) {
            throw new RuntimeException('Failed to parse VTH template: '.$slug);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register         = $this->settingsService->getConfigValue('register');
        $caseTypeSchema   = $this->settingsService->getConfigValue('case_type_schema');
        $statusTypeSchema = $this->settingsService->getConfigValue('status_type_schema');
        $roleTypeSchema   = $this->settingsService->getConfigValue('role_type_schema');
        $docTypeSchema    = $this->settingsService->getConfigValue('document_type_schema');
        $propDefSchema    = $this->settingsService->getConfigValue('property_definition_schema');

        if ($register === '' || $caseTypeSchema === '') {
            throw new RuntimeException('Procest register or case type schema not configured');
        }

        $caseTypeData = array_merge(
            $template['caseType'] ?? [],
            ['slug' => $template['id']]
        );

        // Create or update the case type (idempotent by slug).
        $existing = $objectService->findObjects(
            register: $register,
            schema: $caseTypeSchema,
            params: ['slug' => $template['id'], '_limit' => 1]
        );

        $caseTypeObj = null;
        if (empty($existing) === false) {
            $firstItem = $existing[0] ?? null;
            $row       = [];
            if (is_array($firstItem) === true) {
                $row = $firstItem;
            }

            if (isset($row['id']) === true) {
                $caseTypeData['id'] = $row['id'];
                $caseTypeObj        = $objectService->saveObject(
                    register: $register,
                    schema: $caseTypeSchema,
                    object: $caseTypeData
                );
            }
        }

        if ($caseTypeObj === null) {
            $caseTypeObj = $objectService->saveObject(
                register: $register,
                schema: $caseTypeSchema,
                object: $caseTypeData
            );
        }

        if (is_object($caseTypeObj) === true) {
            $caseTypeId = $caseTypeObj->getUuid();
        } else if (is_array($caseTypeObj) === true) {
            $caseTypeId = $caseTypeObj['id'] ?? '';
        } else {
            $caseTypeId = '';
        }

        $counts = ['statusTypes' => 0, 'roleTypes' => 0, 'documentTypes' => 0, 'propertyDefinitions' => 0];

        // Seed sub-objects if schemas are configured.
        if ($statusTypeSchema !== '' && isset($template['statusTypes']) === true) {
            $counts['statusTypes'] = $this->seedSubObjects(
                objectService: $objectService,
                register: $register,
                schema: $statusTypeSchema,
                items: $template['statusTypes'],
                caseTypeId: $caseTypeId,
                caseTypeField: 'caseType'
            );
        }

        if ($roleTypeSchema !== '' && isset($template['roleTypes']) === true) {
            $counts['roleTypes'] = $this->seedSubObjects(
                objectService: $objectService,
                register: $register,
                schema: $roleTypeSchema,
                items: $template['roleTypes'],
                caseTypeId: $caseTypeId,
                caseTypeField: 'caseType'
            );
        }

        if ($docTypeSchema !== '' && isset($template['documentTypes']) === true) {
            $counts['documentTypes'] = $this->seedSubObjects(
                objectService: $objectService,
                register: $register,
                schema: $docTypeSchema,
                items: $template['documentTypes'],
                caseTypeId: $caseTypeId,
                caseTypeField: 'caseType'
            );
        }

        if ($propDefSchema !== '' && isset($template['propertyDefinitions']) === true) {
            $counts['propertyDefinitions'] = $this->seedSubObjects(
                objectService: $objectService,
                register: $register,
                schema: $propDefSchema,
                items: $template['propertyDefinitions'],
                caseTypeId: $caseTypeId,
                caseTypeField: 'caseType'
            );
        }

        $this->logger->info(
            'VTH template activated: '.$slug.' (caseType='.$caseTypeId.')',
            ['app' => 'procest']
        );

        return ['caseTypeId' => $caseTypeId, 'template' => $slug, 'counts' => $counts];
    }//end activateTemplate()

    /**
     * Seed sub-objects (statusTypes, roleTypes, etc.) for a case type.
     *
     * Existing items are matched by name; new ones are created.
     *
     * @param object            $objectService The OpenRegister object service
     * @param string            $register      Register slug
     * @param string            $schema        Schema slug
     * @param array<int, mixed> $items         Array of item data from template
     * @param string            $caseTypeId    UUID of the parent case type
     * @param string            $caseTypeField Field name linking to caseType
     *
     * @return int Number of items created or updated
     */
    private function seedSubObjects(
        object $objectService,
        string $register,
        string $schema,
        array $items,
        string $caseTypeId,
        string $caseTypeField
    ): int {
        $count = 0;

        foreach ($items as $item) {
            if (is_array($item) === false) {
                continue;
            }

            $item[$caseTypeField] = $caseTypeId;

            try {
                $objectService->saveObject(
                    register: $register,
                    schema: $schema,
                    object: $item
                );
                $count++;
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Failed to seed sub-object: '.$e->getMessage(),
                    ['app' => 'procest', 'schema' => $schema]
                );
            }
        }//end foreach

        return $count;
    }//end seedSubObjects()

    /**
     * Load and decode a template JSON file.
     *
     * @param string $path Absolute path to the JSON file
     *
     * @return array<string, mixed>|null Decoded array or null on failure
     */
    private function loadFile(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded) === false) {
            return null;
        }

        return $decoded;
    }//end loadFile()
}//end class
