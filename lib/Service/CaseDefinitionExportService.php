<?php

/**
 * Dossiq Case Definition Export Service
 *
 * Service for exporting complete case type definitions as portable ZIP archives
 * for DTAP (Development, Test, Acceptance, Production) pipeline deployment.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-procest/tasks.md#task-3
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use RuntimeException;
use ZipArchive;

/**
 * Service for exporting case type definitions as portable ZIP archives.
 *
 * A case definition package contains the schema, status types, result types,
 * permission rules, document types, and workflow definitions for a case type.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archive/retrofit-2026-05-24-annotate-procest/tasks.md#task-3
 */
class CaseDefinitionExportService {
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
	 * The settings keys naming the schema each component reads.
	 *
	 * One map rather than a key repeated per method: a schema renamed in the
	 * settings has one place to change, and a component naming a key nothing
	 * answers to reads as a component with no rows, which is the defect this
	 * whole change is about.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMA_KEYS = [
		'propertyDefinitions' => 'property_definition_schema',
		'statusTypes' => 'status_type_schema',
		'roleTypes' => 'role_type_schema',
		'documentTypes' => 'document_type_schema',
		'resultTypes' => 'result_type_schema',
		'workflowTemplates' => 'workflow_template_schema',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig The Nextcloud app config service.
	 * @param LoggerInterface $logger The logger instance.
	 * @param CaseTypeStore $caseTypes The app's one reader of a case type and
	 *                                 the rows that belong to it. Reading
	 *                                 through it rather than through a second
	 *                                 query here is what keeps the export
	 *                                 answering the same rows the authoring
	 *                                 screens show.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly CaseTypeStore $caseTypes,
	) {
	}//end __construct()

	/**
	 * Export a case definition as a ZIP archive.
	 *
	 * @param string $caseTypeId The OpenRegister ID of the case type to export.
	 * @param string[] $components List of components to include (defaults to all).
	 *
	 * @return array{path: string, filename: string} Temporary file path and suggested filename.
	 *
	 * @throws \RuntimeException If export fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function exportCaseDefinition(
		string $caseTypeId,
		array $components = [],
	): array {
		if (empty($components) === true) {
			$components = self::COMPONENTS;
		}

		// Validate requested components.
		$invalidComponents = array_diff($components, self::COMPONENTS);
		if (empty($invalidComponents) === false) {
			throw new InvalidArgumentException(
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

		// 🔴 THE CASE TYPE IS READ BEFORE ANYTHING IS WRITTEN. A case type that
		// does not resolve used to produce a perfectly formed ZIP of empty
		// components, which an administrator carries to production and imports
		// over nothing. An empty package and a package of a case type with no
		// statuses look identical on disk, so the refusal has to happen here,
		// before the archive exists.
		$caseType = $this->caseTypes->readCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			throw new RuntimeException(
				'No case type answers to "' . $caseTypeId . '", so there is nothing to export.'
			);
		}

		// Build the manifest.
		$manifest = $this->buildManifest(
			caseTypeId: $caseTypeId,
			caseType: $caseType,
			components: $components
		);

		// Create temporary ZIP file.
		$tempPath = tempnam(sys_get_temp_dir(), 'dossiq_export_');
		if ($tempPath === false) {
			throw new RuntimeException('Failed to create temporary file for export');
		}

		$this->writeArchive(
			tempPath: $tempPath,
			caseTypeId: $caseTypeId,
			caseType: $caseType,
			manifest: $manifest,
			components: $components
		);

		$slug = $manifest['caseType']['slug'] ?? 'unknown';
		$version = $manifest['version'] ?? '1.0';

		return [
			'path' => $tempPath,
			'filename' => "case-definition-{$slug}-v{$version}.zip",
		];
	}//end exportCaseDefinition()

	/**
	 * Write the manifest and the selected components into the export archive.
	 *
	 * @param string $tempPath Path of the temporary ZIP file to write.
	 * @param string $caseTypeId The case type ID being exported.
	 * @param array<string, mixed> $caseType The case type object itself.
	 * @param array<string, mixed> $manifest The manifest to store as manifest.json.
	 * @param string[] $components The components to include.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the ZIP archive cannot be created.
	 */
	private function writeArchive(
		string $tempPath,
		string $caseTypeId,
		array $caseType,
		array $manifest,
		array $components,
	): void {
		$zip = new ZipArchive();
		$result = $zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		if ($result !== true) {
			throw new RuntimeException('Failed to create ZIP archive: error code ' . $result);
		}

		// Add manifest.
		$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

		// Add selected components.
		foreach ($components as $component) {
			$data = $this->exportComponent(
				caseTypeId: $caseTypeId,
				caseType: $caseType,
				component: $component
			);
			if ($data === null) {
				continue;
			}

			if ($component === 'workflows') {
				$this->addWorkflowEntries(zip: $zip, workflows: $data);
				continue;
			}

			$zip->addFromString(
				$component . '.json',
				json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
			);
		}//end foreach

		$zip->close();
	}//end writeArchive()

	/**
	 * Add one JSON entry per workflow under the archive's workflows/ directory.
	 *
	 * @param ZipArchive $zip The opened ZIP archive.
	 * @param array<string, mixed> $workflows The workflow data keyed by workflow name.
	 *
	 * @return void
	 */
	private function addWorkflowEntries(ZipArchive $zip, array $workflows): void {
		foreach ($workflows as $workflowName => $workflowData) {
			$zip->addFromString(
				'workflows/' . $workflowName . '.json',
				json_encode($workflowData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
			);
		}
	}//end addWorkflowEntries()

	/**
	 * Build the manifest for a case definition export.
	 *
	 * @param string $caseTypeId The case type ID.
	 * @param array<string, mixed> $caseType The case type object itself.
	 * @param string[] $components The components included in this export.
	 *
	 * @return array<string, mixed> The manifest data.
	 */
	private function buildManifest(string $caseTypeId, array $caseType, array $components): array {
		$previousVersion = $this->appConfig->getValueString(
			Application::APP_ID,
			'export_version_' . $caseTypeId,
			'0.0'
		);

		$newVersion = $this->incrementVersion(version: $previousVersion);

		// Store the new version.
		$this->appConfig->setValueString(
			Application::APP_ID,
			'export_version_' . $caseTypeId,
			$newVersion
		);

		$excludedComponents = array_values(array_diff(self::COMPONENTS, $components));

		$previousVersionValue = null;
		if ($previousVersion !== '0.0') {
			$previousVersionValue = $previousVersion;
		}

		return [
			'version' => $newVersion,
			'previousVersion' => $previousVersionValue,
			'exportDate' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'sourceEnvironment' => $this->appConfig->getValueString(
				Application::APP_ID,
				'environment_name',
				'unknown'
			),
			'generator' => 'dossiq/' . $this->appConfig->getValueString(
				'dossiq',
				'installed_version',
				'unknown'
			),
			// 🔑 THE SLUG COMES FROM THE OBJECT. It used to be the requested id
			// echoed back, so every package was named after the uuid it was
			// exported from and two packages of the same case type from two
			// instances carried two different slugs. `identifier` is ZGW's
			// `identificatie`, which is what makes two rows versions of one
			// zaaktype, so it is the slug when the object carries one.
			'caseType' => [
				'id' => $caseTypeId,
				'slug' => $this->slugOf(caseType: $caseType, caseTypeId: $caseTypeId),
				'title' => trim((string)($caseType['title'] ?? '')),
			],
			'components' => $components,
			'excludedComponents' => $excludedComponents,
			// Every object ref the package points at, so an importer can refuse
			// a package whose references it cannot resolve rather than writing
			// half of it and discovering the rest is missing afterwards.
			'dependencies' => $this->dependenciesOf(caseTypeId: $caseTypeId, caseType: $caseType),
		];
	}//end buildManifest()


	/**
	 * The slug a package is named after.
	 *
	 * @param array<string, mixed> $caseType The case type object.
	 * @param string $caseTypeId The requested id, as a last resort.
	 *
	 * @return string The slug.
	 */
	private function slugOf(array $caseType, string $caseTypeId): string {
		$value = trim((string)($caseType['slug'] ?? ''));
		if ($value !== '') {
			return $value;
		}

		// The STORE's slug next, and `identifier` only after it. OpenRegister
		// mints the slug and a municipality writes the identificatie, so the
		// two disagree on a case type whose identificatie was corrected; the
		// package is named after the thing the store can find again.
		$self = ($caseType['@self'] ?? []);
		if (is_array($self) === true) {
			$value = trim((string)($self['slug'] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		$value = trim((string)($caseType['identifier'] ?? ''));
		if ($value !== '') {
			return $value;
		}

		return $caseTypeId;
	}//end slugOf()


	/**
	 * Every object ref the exported components point at.
	 *
	 * The refs are the ids of the rows themselves plus the case type's own
	 * outward references. An importer reads this list to decide whether it can
	 * resolve the package before it writes any of it.
	 *
	 * @param string $caseTypeId The case type ID.
	 * @param array<string, mixed> $caseType The case type object.
	 *
	 * @return array<int, string> The refs, unique and in a stable order.
	 */
	private function dependenciesOf(string $caseTypeId, array $caseType): array {
		$refs = [$caseTypeId];

		foreach (self::SCHEMA_KEYS as $schemaKey) {
			foreach ($this->rowsOf(schemaKey: $schemaKey, caseTypeId: $caseTypeId) as $row) {
				$refs[] = $this->caseTypes->rowId(row: $row);
			}
		}

		foreach (['decisionTypes', 'relatedCaseTypes', 'subCaseTypes', 'parentCaseType', 'defaultInformatieobjecttype'] as $key) {
			$value = ($caseType[$key] ?? null);
			$entries = [$value];
			if (is_array($value) === true) {
				$entries = $value;
			}

			foreach ($entries as $entry) {
				if (is_string($entry) === true && trim($entry) !== '') {
					$refs[] = trim($entry);
				}
			}
		}

		$refs = array_values(array_unique(array_filter($refs, static fn (string $ref): bool => $ref !== '')));
		sort($refs);

		return $refs;
	}//end dependenciesOf()


	/**
	 * The rows of one schema that belong to this case type.
	 *
	 * @param string $schemaKey The settings key naming the schema.
	 * @param string $caseTypeId The case type ID.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsOf(string $schemaKey, string $caseTypeId): array {
		return $this->caseTypes->rowsOfType(schemaKey: $schemaKey, caseTypeId: $caseTypeId);
	}//end rowsOf()

	/**
	 * Export a single component, from the register.
	 *
	 * 🔴 THIS USED TO RETURN A FIXED EMPTY SHAPE, for every case type on every
	 * instance, under a comment saying "Placeholder: in a full implementation,
	 * this would query OpenRegister". The endpoints answered, the ZIP was real,
	 * the manifest was real and every component inside it was empty. Two things
	 * hid it: `openspec/specs/case-types/spec.md` REQ-CT-17 and REQ-CT-18
	 * already said SHALL, and the archived change ticked "Create
	 * CaseDefinitionExportService with exportCaseDefinition() method", which is
	 * true of the method and false of the feature.
	 *
	 * @param string $caseTypeId The case type ID.
	 * @param array<string, mixed> $caseType The case type object itself.
	 * @param string $component The component name.
	 *
	 * @return array<string, mixed>|null The component data, or null for a name
	 *                                   this service does not know.
	 *
	 * @spec openspec/changes/case-definition-export-is-real/specs/case-types/spec.md
	 */
	private function exportComponent(string $caseTypeId, array $caseType, string $component): ?array {
		return match ($component) {
			'schema' => [
				'caseTypeId' => $caseTypeId,
				'caseType' => $caseType,
				'propertyDefinitions' => $this->rowsOf(
					schemaKey: self::SCHEMA_KEYS['propertyDefinitions'],
					caseTypeId: $caseTypeId
				),
			],
			'statuses' => [
				'statusTypes' => $this->rowsOf(
					schemaKey: self::SCHEMA_KEYS['statusTypes'],
					caseTypeId: $caseTypeId
				),
				// The transitions are the workflow templates' own, because that
				// is where they live: `statusType` declares no transition, and
				// a `transitions: []` beside a populated `statusTypes` reads as
				// a case type whose statuses connect to nothing.
				'transitions' => $this->transitionsOf(caseTypeId: $caseTypeId),
			],
			'permissions' => [
				'roleTypes' => $this->rowsOf(
					schemaKey: self::SCHEMA_KEYS['roleTypes'],
					caseTypeId: $caseTypeId
				),
				'rightsMatrix' => ($caseType['rightsMatrix'] ?? []),
			],
			'documents' => [
				'documentTypes' => $this->rowsOf(
					schemaKey: self::SCHEMA_KEYS['documentTypes'],
					caseTypeId: $caseTypeId
				),
				'defaultInformatieobjecttype' => ($caseType['defaultInformatieobjecttype'] ?? null),
			],
			'metadata' => [
				'resultTypes' => $this->rowsOf(
					schemaKey: self::SCHEMA_KEYS['resultTypes'],
					caseTypeId: $caseTypeId
				),
				// Decision types are a REFERENCE LIST on the case type rather
				// than rows carrying a `caseType` back-reference, so they are
				// read where they are written. Exporting them as rows would
				// answer the empty set on every instance.
				'decisionTypes' => ($caseType['decisionTypes'] ?? []),
			],
			'workflows' => $this->workflowsOf(caseTypeId: $caseTypeId),
			default => null,
		};
	}//end exportComponent()


	/**
	 * The transitions the case type's workflow templates declare.
	 *
	 * @param string $caseTypeId The case type ID.
	 *
	 * @return array<int, array<string, mixed>> The transitions.
	 */
	private function transitionsOf(string $caseTypeId): array {
		$transitions = [];
		foreach ($this->rowsOf(schemaKey: self::SCHEMA_KEYS['workflowTemplates'], caseTypeId: $caseTypeId) as $template) {
			$declared = ($template['transitions'] ?? []);
			if (is_array($declared) === false) {
				continue;
			}

			foreach ($declared as $transition) {
				if (is_array($transition) === true) {
					$transition['workflowTemplate'] = $this->caseTypes->rowId(row: $template);
					$transitions[] = $transition;
				}
			}
		}

		return $transitions;
	}//end transitionsOf()


	/**
	 * The workflow templates bound to the case type, keyed by archive entry name.
	 *
	 * The key becomes a filename, so it is derived from the title and falls
	 * back to the id: two templates with the same title would otherwise write
	 * one entry twice and the second would silently replace the first.
	 *
	 * @param string $caseTypeId The case type ID.
	 *
	 * @return array<string, array<string, mixed>> The templates by entry name.
	 */
	private function workflowsOf(string $caseTypeId): array {
		$workflows = [];
		foreach ($this->rowsOf(schemaKey: self::SCHEMA_KEYS['workflowTemplates'], caseTypeId: $caseTypeId) as $template) {
			$id = $this->caseTypes->rowId(row: $template);
			$title = trim((string)($template['title'] ?? ''));
			$name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $title);
			$name = trim((string)$name, '-');
			if ($name === '') {
				$name = $id;
			}

			if ($name === '') {
				continue;
			}

			if (array_key_exists($name, $workflows) === true) {
				$name .= '-' . $id;
			}

			$workflows[$name] = $template;
		}

		return $workflows;
	}//end workflowsOf()

	/**
	 * Increment a version string (e.g., "1.0" -> "1.1").
	 *
	 * @param string $version The current version string.
	 *
	 * @return string The incremented version string.
	 */
	private function incrementVersion(string $version): string {
		$parts = explode('.', $version);
		$major = (int)($parts[0] ?? 1);
		$minor = (int)($parts[1] ?? 0);

		return sprintf(self::VERSION_FORMAT, $major, $minor + 1);
	}//end incrementVersion()
}//end class
