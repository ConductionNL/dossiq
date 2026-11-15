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
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\CaseDefinitionExportServiceInterface;
use OCA\Dossiq\Service\Support\LanguageMapText;
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
class CaseDefinitionExportService implements CaseDefinitionExportServiceInterface {
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
	 * @param IAppConfig $appConfig The Nextcloud app config service.
	 * @param LoggerInterface $logger The logger instance.
	 * @param CaseTypeStore $caseTypes The app's one reader of a case type and
	 *                                 the rows that belong to it. Reading
	 *                                 through it rather than through a second
	 *                                 query here is what keeps the export
	 *                                 answering the same rows the authoring
	 *                                 screens show.
	 * @param LanguageMapText $text A translatable title or name as one string.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly CaseTypeStore $caseTypes,
		private readonly LanguageMapText $text = new LanguageMapText(),
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function exportCaseDefinition(
		string $caseTypeId,
		array $components = [],
	): array {
		$components = $this->requestedComponents(components: $components);
		$this->logExport(caseTypeId: $caseTypeId, components: $components);

		$export = new CaseDefinitionExport(caseTypeId: $caseTypeId, caseType: $this->existingCaseType(caseTypeId: $caseTypeId));
		$export->components = $components;
		$export->manifest = $this->buildManifest(export: $export);
		$tempPath = $this->exportPath();
		$this->writeArchive(tempPath: $tempPath, export: $export);

		return [
			'path' => $tempPath,
			'filename' => $this->filenameOf(manifest: $export->manifest),
		];
	}//end exportCaseDefinition()

	/**
	 * The components asked for, all of them when none are named.
	 *
	 * @param string[] $components The components asked for.
	 *
	 * @return string[]
	 *
	 * @throws InvalidArgumentException When one of them is not a component.
	 */
	private function requestedComponents(array $components): array {
		if (empty($components) === true) {
			$components = self::COMPONENTS;
		}

		$invalidComponents = array_diff($components, self::COMPONENTS);
		if (empty($invalidComponents) === false) {
			throw new InvalidArgumentException(
				'Invalid export components: ' . implode(', ', $invalidComponents)
			);
		}

		return $components;
	}//end requestedComponents()

	/**
	 * Log what is exported.
	 *
	 * @param string   $caseTypeId The case type.
	 * @param string[] $components The components.
	 *
	 * @return void
	 */
	private function logExport(string $caseTypeId, array $components): void {
		$this->logger->info(
			'Exporting case definition {caseTypeId} with components: {components}',
			[
				'caseTypeId' => $caseTypeId,
				'components' => implode(', ', $components),
			]
		);
	}//end logExport()

	/**
	 * The case type to export.
	 *
	 * 🔴 THE CASE TYPE IS READ BEFORE ANYTHING IS WRITTEN. A case type that
	 * does not resolve used to produce a perfectly formed ZIP of empty
	 * components, which an administrator carries to production and imports
	 * over nothing. An empty package and a package of a case type with no
	 * statuses look identical on disk, so the refusal has to happen here,
	 * before the archive exists.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When no case type answers to the id.
	 */
	private function existingCaseType(string $caseTypeId): array {
		$caseType = $this->caseTypes->readCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			throw new RuntimeException(
				'No case type answers to "' . $caseTypeId . '", so there is nothing to export.'
			);
		}

		return $caseType;
	}//end existingCaseType()

	/**
	 * A temporary file for the archive.
	 *
	 * @return string
	 *
	 * @throws RuntimeException When none can be created.
	 */
	private function exportPath(): string {
		$tempPath = tempnam(sys_get_temp_dir(), 'dossiq_export_');
		if ($tempPath === false) {
			throw new RuntimeException('Failed to create temporary file for export');
		}

		return $tempPath;
	}//end exportPath()

	/**
	 * The file name a package is offered under.
	 *
	 * @param array<string, mixed> $manifest The manifest.
	 *
	 * @return string
	 */
	private function filenameOf(array $manifest): string {
		$slug = $manifest['caseType']['slug'] ?? 'unknown';
		$version = $manifest['version'] ?? '1.0';

		return "case-definition-{$slug}-v{$version}.zip";
	}//end filenameOf()

	/**
	 * Write the manifest and the selected components into the export archive.
	 *
	 * @param string               $tempPath Path of the temporary ZIP file to write.
	 * @param CaseDefinitionExport $export   The export, with its manifest.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the ZIP archive cannot be created.
	 */
	private function writeArchive(string $tempPath, CaseDefinitionExport $export): void {
		$zip = new ZipArchive();
		$result = $zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
		if ($result !== true) {
			throw new RuntimeException('Failed to create ZIP archive: error code ' . $result);
		}

		$zip->addFromString('manifest.json', json_encode($export->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		$this->addComponents(zip: $zip, export: $export);
		$zip->close();
	}//end writeArchive()

	/**
	 * Add the selected components to the archive.
	 *
	 * @param ZipArchive           $zip    The opened ZIP archive.
	 * @param CaseDefinitionExport $export The export.
	 *
	 * @return void
	 */
	private function addComponents(ZipArchive $zip, CaseDefinitionExport $export): void {
		$contents = new CaseDefinitionContents(caseTypes: $this->caseTypes);
		foreach ($export->components as $component) {
			$data = $contents->exportComponent(export: $export, component: $component);
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
	}//end addComponents()

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
	 * @param CaseDefinitionExport $export The export.
	 *
	 * @return array<string, mixed> The manifest data.
	 */
	private function buildManifest(CaseDefinitionExport $export): array {
		$versions = $this->bumpExportVersion(caseTypeId: $export->caseTypeId);

		return [
			'version' => $versions[0],
			'previousVersion' => $versions[1],
			'exportDate' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'sourceEnvironment' => $this->sourceEnvironment(),
			'generator' => $this->generator(),
			'caseType' => $this->manifestCaseType(export: $export),
			'components' => $export->components,
			'excludedComponents' => array_values(array_diff(self::COMPONENTS, $export->components)),
			// Every object ref the package points at, so an importer can refuse
			// a package whose references it cannot resolve rather than writing
			// half of it and discovering the rest is missing afterwards.
			'dependencies' => (new CaseDefinitionContents(caseTypes: $this->caseTypes))->dependenciesOf(export: $export),
		];
	}//end buildManifest()

	/**
	 * Store the next export version of a case type.
	 *
	 * @param string $caseTypeId The case type ID.
	 *
	 * @return array{0: string, 1: string|null} The new version, and the previous one (null on a first export).
	 */
	private function bumpExportVersion(string $caseTypeId): array {
		$previousVersion = $this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'export_version_' . $caseTypeId,
			'0.0'
		);
		$newVersion = $this->incrementVersion(version: $previousVersion);
		$this->appConfig->setValueString(
			AppIdentity::APP_ID,
			'export_version_' . $caseTypeId,
			$newVersion
		);

		if ($previousVersion === '0.0') {
			return [$newVersion, null];
		}

		return [$newVersion, $previousVersion];
	}//end bumpExportVersion()

	/**
	 * The environment this instance says it is.
	 *
	 * @return string
	 */
	private function sourceEnvironment(): string {
		return $this->appConfig->getValueString(
			AppIdentity::APP_ID,
			'environment_name',
			'unknown'
		);
	}//end sourceEnvironment()

	/**
	 * The app and version that wrote the package.
	 *
	 * @return string
	 */
	private function generator(): string {
		return 'dossiq/' . $this->appConfig->getValueString(
			'dossiq',
			'installed_version',
			'unknown'
		);
	}//end generator()

	/**
	 * The case type as the manifest names it.
	 *
	 * 🔑 THE SLUG COMES FROM THE OBJECT. It used to be the requested id echoed
	 * back, so every package was named after the uuid it was exported from and
	 * two packages of the same case type from two instances carried two
	 * different slugs. `identifier` is ZGW's `identificatie`, which is what
	 * makes two rows versions of one zaaktype, so it is the slug when the
	 * object carries one.
	 *
	 * @param CaseDefinitionExport $export The export.
	 *
	 * @return array{id: string, slug: string, title: string}
	 */
	private function manifestCaseType(CaseDefinitionExport $export): array {
		return [
			'id' => $export->caseTypeId,
			'slug' => $this->slugOf(caseType: $export->caseType, caseTypeId: $export->caseTypeId),
			'title' => $this->text->textOf(value: ($export->caseType['title'] ?? null)),
		];
	}//end manifestCaseType()

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
