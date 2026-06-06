<?php

/**
 * Procest VTH Template Service
 *
 * Loads VTH zaaktype template files from lib/Settings/templates/vth-*.json
 * and activates them as zaaktypes in OpenRegister.
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
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for loading and activating VTH zaaktype templates.
 */
class VTHTemplateService
{

    /**
     * Directory containing VTH template files.
     */
    private const TEMPLATE_DIR = __DIR__.'/../Settings/templates';

    /**
     * Allowed VTH template slugs.
     *
     * Wilco #6 / procest#17 path-traversal fix (2026-06-06): the previous
     * `activateTemplate($slug)` interpolated `$slug` directly into the
     * file path (`TEMPLATE_DIR/<slug>.json`), so a caller could send
     * `slug=../../../../etc/passwd` and read arbitrary files. The
     * whitelist below caps the legal values; anything else gets a 422
     * via UnexpectedValueException. New templates are added by extending
     * this list AND placing the matching .json file in TEMPLATE_DIR.
     *
     * @var array<int, string>
     */
    private const ALLOWED_TEMPLATE_SLUGS = [
        'vth-omgevingsvergunning',
        'vth-toezichtzaak',
    ];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService The settings service
     * @param LoggerInterface $logger          The logger
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List available VTH templates.
     *
     * Scans the templates directory for vth-*.json files and returns
     * metadata for each valid template found.
     *
     * @return array<int, array<string, mixed>> List of template metadata
     *
     * @spec openspec/changes/vth-module/tasks.md#task-2
     */
    public function listTemplates(): array
    {
        $templates = [];
        $pattern   = self::TEMPLATE_DIR.'/vth-*.json';
        $files     = glob($pattern);
        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $data = $this->loadTemplateFile(filePath: $file);
            if ($data !== null) {
                $templates[] = [
                    'slug'        => $data['slug'] ?? basename($file, '.json'),
                    'title'       => $data['title'] ?? '',
                    'description' => $data['description'] ?? '',
                    'version'     => $data['version'] ?? '1.0.0',
                    'file'        => basename($file),
                ];
            }
        }

        return $templates;
    }//end listTemplates()

    /**
     * Activate a VTH template by slug.
     *
     * Loads the template file and creates or updates the corresponding
     * zaaktype in OpenRegister using the case_type_schema register.
     *
     * @param string $slug The template slug (e.g. "vth-omgevingsvergunning")
     *
     * @return array<string, mixed> The activated zaaktype record
     *
     * @throws RuntimeException When template is not found or OpenRegister is unavailable.
     *
     * @spec openspec/changes/vth-module/tasks.md#task-2
     */
    public function activateTemplate(string $slug): array
    {
        // Whitelist check (Wilco #6 / procest#17 path-traversal fix). Reject
        // anything not in ALLOWED_TEMPLATE_SLUGS BEFORE building the file
        // path, so a malicious slug never reaches the filesystem.
        if (in_array($slug, self::ALLOWED_TEMPLATE_SLUGS, true) === false) {
            throw new RuntimeException('Unknown VTH template: '.$slug);
        }

        $filePath = self::TEMPLATE_DIR.'/'.$slug.'.json';
        if (is_file($filePath) === false) {
            throw new RuntimeException('VTH template not found: '.$slug);
        }

        $template = $this->loadTemplateFile(filePath: $filePath);
        if ($template === null) {
            throw new RuntimeException('Could not load VTH template: '.$slug);
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new RuntimeException('OpenRegister is not available');
        }

        $register       = $this->settingsService->getConfigValue('register');
        $caseTypeSchema = $this->settingsService->getConfigValue('case_type_schema');
        if ($register === '' || $caseTypeSchema === '') {
            throw new RuntimeException('Procest register or caseType schema not configured');
        }

        // Build the zaaktype record from template metadata.
        $zaaktype = [
            'title'       => $template['title'] ?? $slug,
            'description' => $template['description'] ?? '',
            'identifier'  => $slug,
            'version'     => $template['version'] ?? '1.0.0',
            'isDraft'     => false,
            'validFrom'   => date('Y-m-d'),
        ];

        // Attempt to find an existing zaaktype with this identifier to avoid duplicates.
        $existingId = $this->findExistingZaaktypeId(
            objectService: $objectService,
            register: $register,
            caseTypeSchema: $caseTypeSchema,
            slug: $slug,
        );

        if ($existingId !== null) {
            $zaaktype['id'] = $existingId;
        }

        try {
            $result = $objectService->saveObject($register, $caseTypeSchema, $zaaktype);
        } catch (Throwable $e) {
            $this->logger->error(
                'Procest: failed to activate VTH template '.$slug.': '.$e->getMessage(),
                ['app' => Application::APP_ID],
            );
            throw new RuntimeException('Failed to activate VTH template: '.$e->getMessage());
        }

        $this->logger->info(
            'Procest: activated VTH template '.$slug,
            ['app' => Application::APP_ID],
        );

        return $this->toArray(value: $result) ?? $zaaktype;
    }//end activateTemplate()

    /**
     * Find the ID of an existing zaaktype by its identifier/slug.
     *
     * @param object $objectService  The OpenRegister object service
     * @param string $register       The register slug
     * @param string $caseTypeSchema The case type schema slug
     * @param string $slug           The zaaktype identifier to look up
     *
     * @return string|null The existing record ID, or null when not found
     */
    private function findExistingZaaktypeId(
        object $objectService,
        string $register,
        string $caseTypeSchema,
        string $slug,
    ): ?string {
        try {
            $existing = $objectService->findObjects($register, $caseTypeSchema, ['identifier' => $slug]);
        } catch (Throwable) {
            return null;
        }

        if (is_array($existing) === false || $existing === []) {
            return null;
        }

        $first = is_array($existing[0]) ? $existing[0] : [];
        $id    = $first['id'] ?? ($first['uuid'] ?? null);
        return is_string($id) ? $id : null;
    }//end findExistingZaaktypeId()

    /**
     * Load and decode a template JSON file.
     *
     * @param string $filePath Absolute path to the template file
     *
     * @return array<string, mixed>|null Decoded template data or null on error
     */
    private function loadTemplateFile(string $filePath): ?array
    {
        if (is_file($filePath) === false) {
            return null;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (is_array($data) === false) {
            $this->logger->error(
                'Procest: invalid JSON in template file: '.$filePath,
                ['app' => Application::APP_ID],
            );
            return null;
        }

        return $data;
    }//end loadTemplateFile()

    /**
     * Convert an OpenRegister result to a plain array.
     *
     * @param mixed $value The object or array returned by saveObject
     *
     * @return array<string, mixed>|null Plain array representation or null
     */
    private function toArray(mixed $value): ?array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
            $data = $value->jsonSerialize();
            return is_array($data) ? $data : null;
        }

        return null;
    }//end toArray()
}//end class
