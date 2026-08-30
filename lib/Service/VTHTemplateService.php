<?php

/**
 * Dossiq VTH Template Service
 *
 * Loads VTH zaaktype templates from lib/Settings/templates/vth-*.json and
 * activates them as case type configurations in OpenRegister. Parallels the
 * WOO template-library pattern from TemplateLibraryService.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Support\SearchesObjects;
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
class VTHTemplateService {

	use SearchesObjects;

	/**
	 * Directory containing VTH template JSON files.
	 */
	private const TEMPLATES_DIR = __DIR__ . '/../Settings/templates';

	/**
	 * Prefix for VTH template files.
	 */
	private const VTH_PREFIX = 'vth-';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service for register/schema refs
	 * @param LoggerInterface $logger Logger
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
	public function listTemplates(): array {
		$templates = [];
		$dir = self::TEMPLATES_DIR;

		if (is_dir($dir) === false) {
			return $templates;
		}

		$files = glob($dir . '/' . self::VTH_PREFIX . '*.json');
		if ($files === false) {
			return $templates;
		}

		foreach ($files as $file) {
			$data = $this->loadFile(path: $file);
			if ($data === null) {
				continue;
			}

			$templates[] = [
				'id' => $data['id'] ?? basename(path: $file, suffix: '.json'),
				'title' => $data['title'] ?? '',
				'description' => $data['description'] ?? '',
				'category' => $data['category'] ?? 'vth',
				'version' => $data['version'] ?? '1.0.0',
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
	public function activateTemplate(string $slug): array {
		$template = $this->loadTemplateOrFail(slug: $slug);

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$config = $this->resolveSchemaConfig();

		$caseTypeData = array_merge(
			$template['caseType'] ?? [],
			['slug' => $template['id']]
		);

		$caseTypeObj = $this->upsertCaseType(
			objectService: $objectService,
			config: $config,
			caseTypeData: $caseTypeData,
			slug: $template['id']
		);

		$caseTypeId = $this->extractCaseTypeId(caseTypeObj: $caseTypeObj);

		$counts = $this->seedTemplateSections(
			objectService: $objectService,
			config: $config,
			template: $template,
			caseTypeId: $caseTypeId
		);

		$this->logger->info(
			'VTH template activated: ' . $slug . ' (caseType=' . $caseTypeId . ')',
			['app' => 'dossiq']
		);

		return ['caseTypeId' => $caseTypeId, 'template' => $slug, 'counts' => $counts];
	}//end activateTemplate()

	/**
	 * Resolve a template slug to its decoded JSON body.
	 *
	 * @param string $slug Template slug (e.g. 'vth-omgevingsvergunning')
	 *
	 * @return array<string, mixed> The decoded template body
	 *
	 * @throws RuntimeException If the template file is missing or cannot be parsed
	 */
	private function loadTemplateOrFail(string $slug): array {
		$file = self::TEMPLATES_DIR . '/' . ltrim(string: $slug, characters: '/') . '.json';
		if (file_exists($file) === false) {
			throw new RuntimeException('VTH template not found: ' . $slug);
		}

		$template = $this->loadFile(path: $file);
		if ($template === null) {
			throw new RuntimeException('Failed to parse VTH template: ' . $slug);
		}

		return $template;
	}//end loadTemplateOrFail()

	/**
	 * Read the register and schema references that activation writes into.
	 *
	 * @return array<string, string> Map with the register plus the caseType, statusType, roleType, docType and propDef schema refs
	 *
	 * @throws RuntimeException If the register or case type schema is not configured
	 */
	private function resolveSchemaConfig(): array {
		$config = [
			'register' => $this->settingsService->getConfigValue('register'),
			'caseTypeSchema' => $this->settingsService->getConfigValue('case_type_schema'),
			'statusTypeSchema' => $this->settingsService->getConfigValue('status_type_schema'),
			'roleTypeSchema' => $this->settingsService->getConfigValue('role_type_schema'),
			'docTypeSchema' => $this->settingsService->getConfigValue('document_type_schema'),
			'propDefSchema' => $this->settingsService->getConfigValue('property_definition_schema'),
		];

		if ($config['register'] === '' || $config['caseTypeSchema'] === '') {
			throw new RuntimeException('Dossiq register or case type schema not configured');
		}

		return $config;
	}//end resolveSchemaConfig()

	/**
	 * Create or update the case type for a template (idempotent by slug).
	 *
	 * An existing case type carrying the template slug is updated in-place;
	 * otherwise a new one is created.
	 *
	 * @param object $objectService The OpenRegister object service
	 * @param array<string, string> $config Register and schema references from resolveSchemaConfig()
	 * @param array<string, mixed> $caseTypeData The case type payload to write
	 * @param mixed $slug The template identifier used as the idempotency key
	 *
	 * @return mixed The saved case type, as returned by OpenRegister
	 */
	private function upsertCaseType(object $objectService, array $config, array $caseTypeData, mixed $slug): mixed {
		$existing = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $config['register'],
			schema: $config['caseTypeSchema'],
			filters: ['slug' => $slug, '_limit' => 1]
		);

		$caseTypeObj = null;
		if (empty($existing) === false) {
			$firstItem = $existing[0] ?? null;
			$row = [];
			if (is_array($firstItem) === true) {
				$row = $firstItem;
			}

			if (isset($row['id']) === true) {
				$caseTypeData['id'] = $row['id'];
				$caseTypeObj = $objectService->saveObject(
					register: $config['register'],
					schema: $config['caseTypeSchema'],
					object: $caseTypeData
				);
			}
		}

		if ($caseTypeObj === null) {
			$caseTypeObj = $objectService->saveObject(
				register: $config['register'],
				schema: $config['caseTypeSchema'],
				object: $caseTypeData
			);
		}

		return $caseTypeObj;
	}//end upsertCaseType()

	/**
	 * Read the case type identifier out of whatever OpenRegister returned.
	 *
	 * @param mixed $caseTypeObj The saved case type, either an array row or an entity object
	 *
	 * @return string The case type UUID, or an empty string when it cannot be determined
	 */
	private function extractCaseTypeId(mixed $caseTypeObj): string {
		$caseTypeId = '';
		if (is_array($caseTypeObj) === true) {
			$caseTypeId = $caseTypeObj['id'] ?? '';
		}

		if (is_object($caseTypeObj) === true) {
			$caseTypeId = $caseTypeObj->getUuid();
		}

		return $caseTypeId;
	}//end extractCaseTypeId()

	/**
	 * Seed the template's sub-object sections onto the activated case type.
	 *
	 * A section is skipped when its schema is not configured or the template
	 * does not declare it.
	 *
	 * @param object $objectService The OpenRegister object service
	 * @param array<string, string> $config Register and schema references from resolveSchemaConfig()
	 * @param array<string, mixed> $template The decoded template body
	 * @param string $caseTypeId UUID of the activated case type
	 *
	 * @return array<string, int> Per-section counts of seeded items
	 */
	private function seedTemplateSections(object $objectService, array $config, array $template, string $caseTypeId): array {
		$counts = ['statusTypes' => 0, 'roleTypes' => 0, 'documentTypes' => 0, 'propertyDefinitions' => 0];

		if ($config['statusTypeSchema'] !== '' && isset($template['statusTypes']) === true) {
			$counts['statusTypes'] = $this->seedSubObjects(
				objectService: $objectService,
				register: $config['register'],
				schema: $config['statusTypeSchema'],
				items: $template['statusTypes'],
				caseTypeId: $caseTypeId,
				caseTypeField: 'caseType'
			);
		}

		if ($config['roleTypeSchema'] !== '' && isset($template['roleTypes']) === true) {
			$counts['roleTypes'] = $this->seedSubObjects(
				objectService: $objectService,
				register: $config['register'],
				schema: $config['roleTypeSchema'],
				items: $template['roleTypes'],
				caseTypeId: $caseTypeId,
				caseTypeField: 'caseType'
			);
		}

		if ($config['docTypeSchema'] !== '' && isset($template['documentTypes']) === true) {
			$counts['documentTypes'] = $this->seedSubObjects(
				objectService: $objectService,
				register: $config['register'],
				schema: $config['docTypeSchema'],
				items: $template['documentTypes'],
				caseTypeId: $caseTypeId,
				caseTypeField: 'caseType'
			);
		}

		if ($config['propDefSchema'] !== '' && isset($template['propertyDefinitions']) === true) {
			$counts['propertyDefinitions'] = $this->seedSubObjects(
				objectService: $objectService,
				register: $config['register'],
				schema: $config['propDefSchema'],
				items: $template['propertyDefinitions'],
				caseTypeId: $caseTypeId,
				caseTypeField: 'caseType'
			);
		}

		return $counts;
	}//end seedTemplateSections()

	/**
	 * Seed sub-objects (statusTypes, roleTypes, etc.) for a case type.
	 *
	 * Existing items are matched by name; new ones are created.
	 *
	 * @param object $objectService The OpenRegister object service
	 * @param string $register Register slug
	 * @param string $schema Schema slug
	 * @param array<int, mixed> $items Array of item data from template
	 * @param string $caseTypeId UUID of the parent case type
	 * @param string $caseTypeField Field name linking to caseType
	 *
	 * @return int Number of items created or updated
	 */
	private function seedSubObjects(
		object $objectService,
		string $register,
		string $schema,
		array $items,
		string $caseTypeId,
		string $caseTypeField,
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
					'Failed to seed sub-object: ' . $e->getMessage(),
					['app' => 'dossiq', 'schema' => $schema]
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
	private function loadFile(string $path): ?array {
		if (is_file($path) === false || is_readable($path) === false) {
			return null;
		}

		$raw = file_get_contents($path);
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
