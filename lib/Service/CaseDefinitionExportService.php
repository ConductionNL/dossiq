<?php

/**
 * Procest Case Definition Export Service
 *
 * Service for exporting complete case type definitions as portable ZIP archives
 * for DTAP (Development, Test, Acceptance, Production) pipeline deployment.
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
use Psr\Log\LoggerInterface;

/**
 * Service for exporting case type definitions as portable ZIP archives.
 *
 * A case definition package contains the schema, status types, result types,
 * permission rules, document types, and workflow definitions for a case type.
 *
 * @psalm-suppress UnusedClass
 */
class CaseDefinitionExportService
{
    /**
     * Version format for manifest.
     */
    private const VERSION_FORMAT = '%d.%d';

    /**
     * Available export components.
     *
     * @var string[]
     */
    public const COMPONENTS = [
        'schema',
        'statuses',
        'permissions',
        'documents',
        'metadata',
        'workflows',
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
    }

    /**
     * Export a case definition as a ZIP archive.
     *
     * @param string   $caseTypeId The OpenRegister ID of the case type to export.
     * @param string[] $components List of components to include (defaults to all).
     *
     * @return array{path: string, filename: string} Temporary file path and suggested filename.
     *
     * @throws \RuntimeException If export fails.
     *
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function exportCaseDefinition(
        string $caseTypeId,
        array $components = [],
    ): array {
        if (empty($components)) {
            $components = self::COMPONENTS;
        }

        // Validate requested components.
        $invalidComponents = array_diff($components, self::COMPONENTS);
        if (!empty($invalidComponents)) {
            throw new \InvalidArgumentException(
                'Invalid export components: ' . implode(', ', $invalidComponents)
            );
        }

        $this->logger->info(
            'Exporting case definition {caseTypeId} with components: {components}',
            [
                'caseTypeId' => $caseTypeId,
                'components' => implode(', ', $components),
            ]
        );

        // Build the manifest.
        $manifest = $this->buildManifest($caseTypeId, $components);

        // Create temporary ZIP file.
        $tempPath = tempnam(sys_get_temp_dir(), 'procest_export_');
        if ($tempPath === false) {
            throw new \RuntimeException('Failed to create temporary file for export');
        }

        $zip = new \ZipArchive();
        $result = $zip->open($tempPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($result !== true) {
            throw new \RuntimeException('Failed to create ZIP archive: error code ' . $result);
        }

        // Add manifest.
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Add selected components.
        foreach ($components as $component) {
            $data = $this->exportComponent($caseTypeId, $component);
            if ($data !== null) {
                $filename = $component === 'workflows' ? 'workflows/' : $component . '.json';
                if ($component === 'workflows' && is_array($data)) {
                    foreach ($data as $workflowName => $workflowData) {
                        $zip->addFromString(
                            'workflows/' . $workflowName . '.json',
                            json_encode($workflowData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        );
                    }
                } else {
                    $zip->addFromString(
                        $component . '.json',
                        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                    );
                }
            }
        }

        $zip->close();

        $slug = $manifest['caseType']['slug'] ?? 'unknown';
        $version = $manifest['version'] ?? '1.0';

        return [
            'path' => $tempPath,
            'filename' => "case-definition-{$slug}-v{$version}.zip",
        ];
    }

    /**
     * Build the manifest for a case definition export.
     *
     * @param string   $caseTypeId The case type ID.
     * @param string[] $components The components included in this export.
     *
     * @return array<string, mixed> The manifest data.
     */
    private function buildManifest(string $caseTypeId, array $components): array
    {
        $previousVersion = $this->appConfig->getValueString(
            Application::APP_ID,
            'export_version_' . $caseTypeId,
            '0.0'
        );

        $newVersion = $this->incrementVersion($previousVersion);

        // Store the new version.
        $this->appConfig->setValueString(
            Application::APP_ID,
            'export_version_' . $caseTypeId,
            $newVersion
        );

        $excludedComponents = array_values(array_diff(self::COMPONENTS, $components));

        return [
            'version' => $newVersion,
            'previousVersion' => $previousVersion !== '0.0' ? $previousVersion : null,
            'exportDate' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'sourceEnvironment' => $this->appConfig->getValueString(
                Application::APP_ID,
                'environment_name',
                'unknown'
            ),
            'generator' => 'procest/' . $this->appConfig->getValueString(
                'procest',
                'installed_version',
                'unknown'
            ),
            'caseType' => [
                'id' => $caseTypeId,
                'slug' => $caseTypeId,
            ],
            'components' => $components,
            'excludedComponents' => $excludedComponents,
            'dependencies' => [],
        ];
    }

    /**
     * Export a single component.
     *
     * @param string $caseTypeId The case type ID.
     * @param string $component  The component name.
     *
     * @return array<string, mixed>|null The component data, or null if empty.
     */
    private function exportComponent(string $caseTypeId, string $component): ?array
    {
        // Placeholder: in a full implementation, this would query OpenRegister
        // for the actual data. For now, return structured placeholders that
        // demonstrate the expected format.
        return match ($component) {
            'schema' => [
                'caseTypeId' => $caseTypeId,
                'fields' => [],
                'validations' => [],
            ],
            'statuses' => [
                'statusTypes' => [],
                'transitions' => [],
            ],
            'permissions' => [
                'roles' => [],
                'rules' => [],
            ],
            'documents' => [
                'documentTypes' => [],
                'templates' => [],
            ],
            'metadata' => [
                'resultTypes' => [],
                'decisionTypes' => [],
            ],
            'workflows' => [],
            default => null,
        };
    }

    /**
     * Increment a version string (e.g., "1.0" -> "1.1").
     *
     * @param string $version The current version string.
     *
     * @return string The incremented version string.
     */
    private function incrementVersion(string $version): string
    {
        $parts = explode('.', $version);
        $major = (int)($parts[0] ?? 1);
        $minor = (int)($parts[1] ?? 0);

        return sprintf(self::VERSION_FORMAT, $major, $minor + 1);
    }
}
