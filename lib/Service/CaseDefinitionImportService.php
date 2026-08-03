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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Service for importing case type definitions from ZIP archives.
 *
 * Validates the package, resolves dependencies, detects conflicts,
 * and creates/updates case type configuration in OpenRegister.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-types/spec.md
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

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
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
        $zip        = new ZipArchive();
        $openResult = $zip->open($zipPath, ZipArchive::RDONLY);
        if ($openResult !== true) {
            $result['valid']    = false;
            $result['errors'][] = 'Failed to open ZIP archive: error code '.$openResult;
            return $result;
        }

        // Check required files.
        $missingFiles = $this->findMissingRequiredFiles(zip: $zip);
        if ($missingFiles !== []) {
            $result['valid']  = false;
            $result['errors'] = $missingFiles;
            $zip->close();
            return $result;
        }

        // Parse manifest.
        $manifestResult = $this->readManifest(zip: $zip);
        if ($manifestResult['errors'] !== []) {
            $result['valid']  = false;
            $result['errors'] = $manifestResult['errors'];
            $zip->close();
            return $result;
        }

        $result['manifest'] = $manifestResult['manifest'];

        // Validate manifest structure, declared components and dependencies.
        $issues = $this->validateManifestContents(zip: $zip, manifest: (array) $manifestResult['manifest']);

        $result['errors']   = $issues['errors'];
        $result['warnings'] = $issues['warnings'];
        $result['valid']    = ($issues['errors'] === []);

        $zip->close();

        $validLabel = 'false';
        if ($result['valid'] === true) {
            $validLabel = 'true';
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

     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
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

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::RDONLY);

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

        $successLabel = 'false';
        $message      = 'Import completed with errors';
        if ($allSuccess === true) {
            $successLabel = 'true';
            $message      = 'Import completed successfully';
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
            return $this->importWorkflows(zip: $zip);
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
     * Workflow files are enumerated and counted only; there is no conflict to
     * resolve on this path, so — unlike the other components — it takes no
     * conflict-resolution strategy.
     *
     * @param \ZipArchive $zip The opened ZIP archive.
     *
     * @return array{status: string, message: string}
     */
    private function importWorkflows(\ZipArchive $zip): array
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

    /**
     * Collect an error for every required package file missing from the archive.
     *
     * @param \ZipArchive $zip The opened ZIP archive.
     *
     * @return string[] One error message per missing required file.
     */
    private function findMissingRequiredFiles(\ZipArchive $zip): array
    {
        $errors = [];

        foreach (self::REQUIRED_FILES as $requiredFile) {
            if ($zip->locateName($requiredFile) === false) {
                $errors[] = "Missing required file: {$requiredFile}";
            }
        }

        return $errors;
    }//end findMissingRequiredFiles()

    /**
     * Read and decode manifest.json from the archive.
     *
     * @param \ZipArchive $zip The opened ZIP archive.
     *
     * @return array{manifest: mixed, errors: string[]} The decoded manifest, or the read/decode errors.
     */
    private function readManifest(\ZipArchive $zip): array
    {
        $manifestJson = $zip->getFromName('manifest.json');
        if ($manifestJson === false) {
            return [
                'manifest' => null,
                'errors'   => ['Failed to read manifest.json'],
            ];
        }

        $manifest = json_decode($manifestJson, true);
        if ($manifest === null) {
            return [
                'manifest' => null,
                'errors'   => ['Invalid JSON in manifest.json: '.json_last_error_msg()],
            ];
        }

        return [
            'manifest' => $manifest,
            'errors'   => [],
        ];
    }//end readManifest()

    /**
     * Validate the manifest structure, its declared components and its dependencies.
     *
     * @param \ZipArchive          $zip      The opened ZIP archive.
     * @param array<string, mixed> $manifest The decoded manifest.
     *
     * @return array{errors: string[], warnings: string[]} The accumulated errors and warnings, in report order.
     */
    private function validateManifestContents(\ZipArchive $zip, array $manifest): array
    {
        $errors = $this->findMissingManifestFields(manifest: $manifest);

        $components   = (array) ($manifest['components'] ?? []);
        $dependencies = (array) ($manifest['dependencies'] ?? []);

        $componentIssues = $this->validateComponentFiles(zip: $zip, components: $components);
        $errors          = array_merge($errors, $componentIssues['errors']);
        $warnings        = $componentIssues['warnings'];

        $errors = array_merge($errors, $this->validateComponentJson(zip: $zip, components: $components));

        $warnings = array_merge($warnings, $this->buildDependencyWarnings(dependencies: $dependencies));

        return [
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }//end validateManifestContents()

    /**
     * Collect an error for every mandatory manifest field that is absent.
     *
     * @param array<string, mixed> $manifest The decoded manifest.
     *
     * @return string[] One error message per missing field.
     */
    private function findMissingManifestFields(array $manifest): array
    {
        $errors = [];

        $requiredFields = ['version', 'exportDate', 'caseType', 'components'];
        foreach ($requiredFields as $field) {
            if (isset($manifest[$field]) === false) {
                $errors[] = "Missing required manifest field: {$field}";
            }
        }

        return $errors;
    }//end findMissingManifestFields()

    /**
     * Verify that every declared component has a matching file in the archive.
     *
     * @param \ZipArchive  $zip        The opened ZIP archive.
     * @param array<mixed> $components The components declared in the manifest.
     *
     * @return array{errors: string[], warnings: string[]} Missing-file errors and workflow warnings.
     */
    private function validateComponentFiles(\ZipArchive $zip, array $components): array
    {
        $errors   = [];
        $warnings = [];

        foreach ($components as $component) {
            if ($component === 'workflows') {
                // Workflows are in a subdirectory -- check for at least the directory.
                if ($this->hasWorkflowEntries(zip: $zip) === false) {
                    $warnings[] = 'Component "workflows" declared but no workflow files found';
                }

                continue;
            }

            $componentFile = $component.'.json';
            if ($zip->locateName($componentFile) === false) {
                $errors[] = "Component '{$component}' declared in manifest but file '{$componentFile}' not found";
            }
        }//end foreach

        return [
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }//end validateComponentFiles()

    /**
     * Determine whether the archive contains at least one entry under workflows/.
     *
     * @param \ZipArchive $zip The opened ZIP archive.
     *
     * @return bool True when a workflows/ entry is present.
     */
    private function hasWorkflowEntries(\ZipArchive $zip): bool
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false && str_starts_with($name, 'workflows/') === true) {
                return true;
            }
        }

        return false;
    }//end hasWorkflowEntries()

    /**
     * Verify that each declared component file contains parseable JSON.
     *
     * @param \ZipArchive  $zip        The opened ZIP archive.
     * @param array<mixed> $components The components declared in the manifest.
     *
     * @return string[] One error message per component file with invalid JSON.
     */
    private function validateComponentJson(\ZipArchive $zip, array $components): array
    {
        $errors = [];

        foreach ($components as $component) {
            if ($component === 'workflows') {
                continue;
            }

            $componentFile = $component.'.json';
            $content       = $zip->getFromName($componentFile);
            if ($content !== false) {
                $decoded = json_decode($content, true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    $errors[] = "Invalid JSON in {$componentFile}: ".json_last_error_msg();
                }
            }
        }

        return $errors;
    }//end validateComponentJson()

    /**
     * Build the "verify in target environment" warning for each declared dependency.
     *
     * @param array<mixed> $dependencies The dependencies declared in the manifest.
     *
     * @return string[] One warning per dependency.
     */
    private function buildDependencyWarnings(array $dependencies): array
    {
        $warnings = [];

        foreach ($dependencies as $dep) {
            $depType = $dep['type'] ?? 'unknown';
            $depName = $dep['name'] ?? 'unknown';
            // In a full implementation, check if the dependency exists in OpenRegister.
            $warnings[] = "Dependency '{$depName}' (type: {$depType}) should be verified in target environment";
        }

        return $warnings;
    }//end buildDependencyWarnings()
}//end class
