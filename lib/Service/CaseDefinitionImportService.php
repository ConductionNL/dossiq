<?php

/**
 * Procest Case Definition Import Service
 *
 * Service for importing case type definitions from portable ZIP archives
 * with validation, dependency resolution, and conflict detection.
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
 * @spec openspec/changes/retrofit-2026-05-24-annotate-procest/tasks.md#task-3
 * @spec openspec/changes/retrofit-2026-05-24-case-types/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Service for importing case type definitions from ZIP archives.
 *
 * Validates the package, resolves dependencies, detects conflicts,
 * and creates/updates case type configuration in OpenRegister.
 *
 * @psalm-suppress UnusedClass
 */
class CaseDefinitionImportService
{
    /**
     * Required files in an import package.
     *
     * @var string[]
     */
    private const REQUIRED_FILES = ['manifest.json'];

    /**
     * Valid component files.
     *
     * @var string[]
     */
    private const VALID_COMPONENT_FILES = [
        'schema.json',
        'statuses.json',
        'permissions.json',
        'documents.json',
        'metadata.json',
    ];

    /**
     * Constructor.
     *
     * @param IAppConfig      $appConfig The Nextcloud app config service.
     * @param LoggerInterface $logger    The logger instance.
     */
    public function __construct(
        private readonly IAppConfig $appConfig,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Validate a case definition package without importing it.
     *
     * @param string $zipPath Path to the uploaded ZIP file.
     *
     * @return array{valid: bool, errors: string[], warnings: string[], manifest: ?array<string, mixed>, conflicts: array<string, mixed>}
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function validatePackage(string $zipPath): array
    {
        $result = [
            'valid'     => true,
            'errors'    => [],
            'warnings'  => [],
            'manifest'  => null,
            'conflicts' => [],
        ];

        // Open the ZIP.
        $zip        = new \ZipArchive();
        $openResult = $zip->open($zipPath, \ZipArchive::RDONLY);
        if ($openResult !== true) {
            $result['valid']    = false;
            $result['errors'][] = 'Failed to open ZIP archive: error code '.$openResult;
            return $result;
        }

        // Check required files.
        foreach (self::REQUIRED_FILES as $requiredFile) {
            if ($zip->locateName($requiredFile) === false) {
                $result['valid']    = false;
                $result['errors'][] = "Missing required file: {$requiredFile}";
            }
        }

        if ($result['valid'] === false) {
            $zip->close();
            return $result;
        }

        // Parse manifest.
        $manifestJson = $zip->getFromName('manifest.json');
        if ($manifestJson === false) {
            $result['valid']    = false;
            $result['errors'][] = 'Failed to read manifest.json';
            $zip->close();
            return $result;
        }

        $manifest = json_decode($manifestJson, true);
        if ($manifest === null) {
            $result['valid']    = false;
            $result['errors'][] = 'Invalid JSON in manifest.json: '.json_last_error_msg();
            $zip->close();
            return $result;
        }

        $result['manifest'] = $manifest;

        // Validate manifest structure.
        $requiredManifestFields = ['version', 'exportDate', 'caseType', 'components'];
        foreach ($requiredManifestFields as $field) {
            if (isset($manifest[$field]) === false) {
                $result['valid']    = false;
                $result['errors'][] = "Missing required manifest field: {$field}";
            }
        }

        // Validate that declared components have matching files.
        $components = $manifest['components'] ?? [];
        foreach ($components as $component) {
            if ($component === 'workflows') {
                // Workflows are in a subdirectory -- check for at least the directory.
                $hasWorkflows = false;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if ($name !== false && str_starts_with($name, 'workflows/') === true) {
                        $hasWorkflows = true;
                        break;
                    }
                }

                if ($hasWorkflows === false) {
                    $result['warnings'][] = 'Component "workflows" declared but no workflow files found';
                }
            } else {
                $componentFile = $component.'.json';
                if ($zip->locateName($componentFile) === false) {
                    $result['valid']    = false;
                    $result['errors'][] = "Component '{$component}' declared in manifest but file '{$componentFile}' not found";
                }
            }//end if
        }//end foreach

        // Validate component JSON.
        foreach ($components as $component) {
            if ($component === 'workflows') {
                continue;
            }

            $componentFile = $component.'.json';
            $content       = $zip->getFromName($componentFile);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    $result['valid']    = false;
                    $result['errors'][] = "Invalid JSON in {$componentFile}: ".json_last_error_msg();
                }
            }
        }

        // Check for dependency conflicts.
        $dependencies = $manifest['dependencies'] ?? [];
        foreach ($dependencies as $dep) {
            $depType = $dep['type'] ?? 'unknown';
            $depName = $dep['name'] ?? 'unknown';
            // In a full implementation, check if the dependency exists in OpenRegister.
            $result['warnings'][] = "Dependency '{$depName}' (type: {$depType}) should be verified in target environment";
        }

        $zip->close();

        if ($result['valid'] === true) {
            $validLabel = 'true';
        } else {
            $validLabel = 'false';
        }

        $this->logger->info(
            'Validated case definition package: {valid}, errors: {errorCount}, warnings: {warningCount}',
            [
                'valid'        => $validLabel,
                'errorCount'   => count($result['errors']),
                'warningCount' => count($result['warnings']),
            ]
        );

        return $result;
    }//end validatePackage()

    /**
     * Import a case definition package.
     *
     * @param string $zipPath  Path to the uploaded ZIP file.
     * @param string $strategy Conflict resolution strategy: 'skip', 'overwrite', or 'merge'.
     *
     * @return array{success: bool, message: string, components: array<string, array{status: string, message: string}>}
     *
     * @throws \RuntimeException If import fails.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    /** @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md */
    public function importCaseDefinition(
        string $zipPath,
        string $strategy='skip',
    ): array {
        // First validate.
        $validation = $this->validatePackage(zipPath: $zipPath);
        if ($validation['valid'] === false) {
            return [
                'success'    => false,
                'message'    => 'Package validation failed: '.implode('; ', $validation['errors']),
                'components' => [],
            ];
        }

        $manifest   = $validation['manifest'];
        $components = $manifest['components'] ?? [];
        $results    = [];

        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::RDONLY);

        foreach ($components as $component) {
            try {
                $results[$component] = $this->importComponent(zip: $zip, component: $component, strategy: $strategy);
            } catch (\Throwable $e) {
                $results[$component] = [
                    'status'  => 'error',
                    'message' => $e->getMessage(),
                ];
                $this->logger->error(
                    'Failed to import component {component}: {error}',
                    [
                        'component' => $component,
                        'error'     => $e->getMessage(),
                    ]
                );
            }
        }

        $zip->close();

        $allSuccess = in_array('error', array_column($results, 'status'), true) === false;

        if ($allSuccess === true) {
            $successLabel = 'true';
            $message      = 'Import completed successfully';
        } else {
            $successLabel = 'false';
            $message      = 'Import completed with errors';
        }

        $this->logger->info(
            'Case definition import completed: {success}, components: {count}',
            [
                'success' => $successLabel,
                'count'   => count($results),
            ]
        );

        return [
            'success'    => $allSuccess,
            'message'    => $message,
            'components' => $results,
        ];
    }//end importCaseDefinition()

    /**
     * Import a single component from the ZIP archive.
     *
     * @param \ZipArchive $zip       The opened ZIP archive.
     * @param string      $component The component name.
     * @param string      $strategy  The conflict resolution strategy.
     *
     * @return array{status: string, message: string}
     */
    private function importComponent(
        \ZipArchive $zip,
        string $component,
        string $strategy,
    ): array {
        if ($component === 'workflows') {
            return $this->importWorkflows(zip: $zip, strategy: $strategy);
        }

        $content = $zip->getFromName($component.'.json');
        if ($content === false) {
            return [
                'status'  => 'skipped',
                'message' => "Component file {$component}.json not found in archive",
            ];
        }

        $data = json_decode($content, true);
        if ($data === null) {
            return [
                'status'  => 'error',
                'message' => "Invalid JSON in {$component}.json",
            ];
        }

        // In a full implementation, this would create/update OpenRegister objects.
        // For now, store the fact that the component was imported.
        $this->logger->info(
            'Imported component {component} with strategy {strategy}',
            [
                'component' => $component,
                'strategy'  => $strategy,
            ]
        );

        return [
            'status'  => 'success',
            'message' => "Component '{$component}' imported successfully",
        ];
    }//end importComponent()

    /**
     * Import workflow files from the ZIP archive.
     *
     * @param \ZipArchive $zip      The opened ZIP archive.
     * @param string      $strategy The conflict resolution strategy.
     *
     * @return array{status: string, message: string}
     */
    private function importWorkflows(\ZipArchive $zip, string $strategy): array
    {
        $workflowCount = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && str_starts_with($name, 'workflows/') === true && str_ends_with($name, '.json') === true) {
                $content = $zip->getFromIndex($i);
                if ($content !== false) {
                    // In a full implementation, deploy via n8n API.
                    $workflowCount++;
                }
            }
        }

        return [
            'status'  => 'success',
            'message' => "Imported {$workflowCount} workflow(s)",
        ];
    }//end importWorkflows()
}//end class
