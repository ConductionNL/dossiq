<?php

/**
 * Dossiq Case Definition Import Service
 *
 * Service for importing case type definitions from portable ZIP archives
 * with validation, dependency resolution, and conflict detection.
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

use OCA\Dossiq\Service\CaseDefinition\PackageWriter;
use Psr\Log\LoggerInterface;
use RuntimeException;
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
class CaseDefinitionImportService {
	/**
	 * Required files in an import package.
	 *
	 * @var string[]
	 */
	private const REQUIRED_FILES = ['manifest.json'];



	/**
	 * The collections each component carries, in write order.
	 *
	 * `caseType` is written first inside `schema`, because every other row
	 * carries a `caseType` back-reference and a row written against a case
	 * type that does not exist yet is a reference no reader can follow.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const COMPONENT_COLLECTIONS = [
		'schema' => ['caseType', 'propertyDefinitions'],
		'statuses' => ['statusTypes'],
		'permissions' => ['roleTypes'],
		'documents' => ['documentTypes'],
		'metadata' => ['resultTypes'],
	];

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger instance.
	 * @param SettingsService $settings The register and schema names, and the
	 *                                  OpenRegister object service this writes
	 *                                  through. dossiq stores nothing of its
	 *                                  own here: the objects are OpenRegister's
	 *                                  and are written the way the rest of the
	 *                                  app writes them (ADR-022).
	 * @param PackageWriter   $writer   Writes the package's rows and workflows.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly SettingsService $settings,
		private readonly PackageWriter $writer,
	) {
	}//end __construct()

	/**
	 * Validate a case definition package without importing it.
	 *
	 * @param string $zipPath Path to the uploaded ZIP file.
	 *
	 * @return array{valid: bool, errors: string[], warnings: string[], manifest: ?array<string, mixed>, conflicts: array<string, mixed>}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function validatePackage(string $zipPath): array {
		$result = [
			'valid' => true,
			'errors' => [],
			'warnings' => [],
			'manifest' => null,
			'conflicts' => [],
		];

		// Open the ZIP.
		$zip = new ZipArchive();
		$openResult = $zip->open($zipPath, ZipArchive::RDONLY);
		if ($openResult !== true) {
			$result['valid'] = false;
			$result['errors'][] = 'Failed to open ZIP archive: error code ' . $openResult;
			return $result;
		}

		// Check required files.
		$missingFiles = $this->findMissingRequiredFiles(zip: $zip);
		if ($missingFiles !== []) {
			$result['valid'] = false;
			$result['errors'] = $missingFiles;
			$zip->close();
			return $result;
		}

		// Parse manifest.
		$manifestResult = $this->readManifest(zip: $zip);
		if ($manifestResult['errors'] !== []) {
			$result['valid'] = false;
			$result['errors'] = $manifestResult['errors'];
			$zip->close();
			return $result;
		}

		$result['manifest'] = $manifestResult['manifest'];

		// Validate manifest structure, declared components and dependencies.
		$issues = $this->validateManifestContents(zip: $zip, manifest: (array)$manifestResult['manifest']);

		$result['errors'] = $issues['errors'];
		$result['warnings'] = $issues['warnings'];
		$result['valid'] = ($issues['errors'] === []);

		$zip->close();

		$validLabel = 'false';
		if ($result['valid'] === true) {
			$validLabel = 'true';
		}

		$this->logger->info(
			'Validated case definition package: {valid}, errors: {errorCount}, warnings: {warningCount}',
			[
				'valid' => $validLabel,
				'errorCount' => count($result['errors']),
				'warningCount' => count($result['warnings']),
			]
		);

		return $result;
	}//end validatePackage()

	/**
	 * Import a case definition package.
	 *
	 * @param string $zipPath Path to the uploaded ZIP file.
	 * @param string $strategy Conflict resolution strategy: 'skip', 'overwrite', or 'merge'.
	 *
	 * @return array{success: bool, message: string, components: array<string, array{status: string, message: string}>}
	 *
	 * @throws \RuntimeException If import fails.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function importCaseDefinition(
		string $zipPath,
		string $strategy = 'skip',
	): array {
		// First validate.
		$validation = $this->validatePackage(zipPath: $zipPath);
		if ($validation['valid'] === false) {
			return [
				'success' => false,
				'message' => 'Package validation failed: ' . implode('; ', $validation['errors']),
				'components' => [],
			];
		}

		$manifest = $validation['manifest'];
		$components = $manifest['components'] ?? [];
		$results = [];

		$zip = new ZipArchive();
		$zip->open($zipPath, ZipArchive::RDONLY);

		foreach ($components as $component) {
			try {
				$results[$component] = $this->importComponent(zip: $zip, component: $component, strategy: $strategy);
			} catch (\Throwable $e) {
				$results[$component] = [
					'status' => 'error',
					'message' => $e->getMessage(),
				];
				$this->logger->error(
					'Failed to import component {component}: {error}',
					[
						'component' => $component,
						'error' => $e->getMessage(),
					]
				);
			}
		}

		$zip->close();

		$allSuccess = in_array('error', array_column($results, 'status'), true) === false;

		$successLabel = 'false';
		$message = 'Import completed with errors';
		if ($allSuccess === true) {
			$successLabel = 'true';
			$message = 'Import completed successfully';
		}

		$this->logger->info(
			'Case definition import completed: {success}, components: {count}',
			[
				'success' => $successLabel,
				'count' => count($results),
			]
		);

		return [
			'success' => $allSuccess,
			'message' => $message,
			'components' => $results,
		];
	}//end importCaseDefinition()

	/**
	 * Import a single component from the ZIP archive.
	 *
	 * 🔴 THIS USED TO REPORT SUCCESS HAVING WRITTEN NOTHING. It read the JSON,
	 * logged a line and returned `status: 'success'` under a comment saying
	 * "In a full implementation, this would create/update OpenRegister
	 * objects". An administrator moving a case type from acceptance to
	 * production got a green dialog and an empty instance, which is worse than
	 * an error, because an error would have been acted on.
	 *
	 * 🔑 ALL OR NOTHING, PER COMPONENT. The rows of one component are written
	 * and the ids kept; a write that fails takes the whole component with it
	 * and deletes what this run had already written for it. A half-imported
	 * case type — statuses without the case type they belong to, roles
	 * pointing at nothing — is a state nobody can read and nobody asked for.
	 *
	 * @param \ZipArchive $zip The opened ZIP archive.
	 * @param string $component The component name.
	 * @param string $strategy The conflict resolution strategy.
	 *
	 * @return array{status: string, message: string, created?: array<int, string>, replaced?: array<int, string>}
	 *
	 * @spec openspec/changes/case-definition-export-is-real/specs/case-types/spec.md
	 */
	private function importComponent(
		\ZipArchive $zip,
		string $component,
		string $strategy,
	): array {
		if ($component === 'workflows') {
			return $this->writer->importWorkflows(zip: $zip, strategy: $strategy);
		}

		$content = $zip->getFromName($component . '.json');
		if ($content === false) {
			return [
				'status' => 'skipped',
				'message' => "Component file {$component}.json not found in archive",
			];
		}

		$data = json_decode($content, true);
		if (is_array($data) === false) {
			return [
				'status' => 'error',
				'message' => "Invalid JSON in {$component}.json",
			];
		}

		$collections = (self::COMPONENT_COLLECTIONS[$component] ?? []);
		if ($collections === []) {
			return [
				'status' => 'error',
				'message' => "Component '{$component}' names no collection this import knows how to write.",
			];
		}

		return $this->writer->writeCollections(
			component: $component,
			data: $data,
			collections: $collections,
			strategy: $strategy
		);
	}//end importComponent()




















	/**
	 * Collect an error for every required package file missing from the archive.
	 *
	 * @param \ZipArchive $zip The opened ZIP archive.
	 *
	 * @return string[] One error message per missing required file.
	 */
	private function findMissingRequiredFiles(\ZipArchive $zip): array {
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
	private function readManifest(\ZipArchive $zip): array {
		$manifestJson = $zip->getFromName('manifest.json');
		if ($manifestJson === false) {
			return [
				'manifest' => null,
				'errors' => ['Failed to read manifest.json'],
			];
		}

		$manifest = json_decode($manifestJson, true);
		if ($manifest === null) {
			return [
				'manifest' => null,
				'errors' => ['Invalid JSON in manifest.json: ' . json_last_error_msg()],
			];
		}

		return [
			'manifest' => $manifest,
			'errors' => [],
		];
	}//end readManifest()

	/**
	 * Validate the manifest structure, its declared components and its dependencies.
	 *
	 * @param \ZipArchive $zip The opened ZIP archive.
	 * @param array<string, mixed> $manifest The decoded manifest.
	 *
	 * @return array{errors: string[], warnings: string[]} The accumulated errors and warnings, in report order.
	 */
	private function validateManifestContents(\ZipArchive $zip, array $manifest): array {
		$errors = $this->findMissingManifestFields(manifest: $manifest);

		$components = (array)($manifest['components'] ?? []);
		$dependencies = (array)($manifest['dependencies'] ?? []);

		$componentIssues = $this->validateComponentFiles(zip: $zip, components: $components);
		$errors = array_merge($errors, $componentIssues['errors']);
		$warnings = $componentIssues['warnings'];

		$errors = array_merge($errors, $this->validateComponentJson(zip: $zip, components: $components));

		$warnings = array_merge(
			$warnings,
			$this->buildDependencyWarnings(zip: $zip, components: $components, dependencies: $dependencies)
		);

		return [
			'errors' => $errors,
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
	private function findMissingManifestFields(array $manifest): array {
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
	 * @param \ZipArchive $zip The opened ZIP archive.
	 * @param array<mixed> $components The components declared in the manifest.
	 *
	 * @return array{errors: string[], warnings: string[]} Missing-file errors and workflow warnings.
	 */
	private function validateComponentFiles(\ZipArchive $zip, array $components): array {
		$errors = [];
		$warnings = [];

		foreach ($components as $component) {
			if ($component === 'workflows') {
				// Workflows are in a subdirectory -- check for at least the directory.
				if ($this->hasWorkflowEntries(zip: $zip) === false) {
					$warnings[] = 'Component "workflows" declared but no workflow files found';
				}

				continue;
			}

			$componentFile = $component . '.json';
			if ($zip->locateName($componentFile) === false) {
				$errors[] = "Component '{$component}' declared in manifest but file '{$componentFile}' not found";
			}
		}//end foreach

		return [
			'errors' => $errors,
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
	private function hasWorkflowEntries(\ZipArchive $zip): bool {
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
	 * @param \ZipArchive $zip The opened ZIP archive.
	 * @param array<mixed> $components The components declared in the manifest.
	 *
	 * @return string[] One error message per component file with invalid JSON.
	 */
	private function validateComponentJson(\ZipArchive $zip, array $components): array {
		$errors = [];

		foreach ($components as $component) {
			if ($component === 'workflows') {
				continue;
			}

			$componentFile = $component . '.json';
			$content = $zip->getFromName($componentFile);
			if ($content !== false) {
				$decoded = json_decode($content, true);
				if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
					$errors[] = "Invalid JSON in {$componentFile}: " . json_last_error_msg();
				}
			}
		}

		return $errors;
	}//end validateComponentJson()

	/**
	 * Warn about every declared dependency the package does not itself carry.
	 *
	 * 🔴 THIS USED TO WARN ABOUT EVERY DEPENDENCY, by name `unknown` and type
	 * `unknown`, under a comment saying a full implementation would check
	 * whether it exists. A warning on every ref is a warning on none: an
	 * administrator reading forty identical lines stops reading them, and the
	 * one ref that actually dangles is in the middle of them.
	 *
	 * The check is PACKAGE-LOCAL and deliberately so. A ref the package
	 * carries a row for resolves by definition once the import has run. A ref
	 * it does not carry is one the target instance has to already hold, and
	 * that is the one worth naming.
	 *
	 * @param \ZipArchive $zip The opened ZIP archive.
	 * @param array<mixed> $components The components declared in the manifest.
	 * @param array<mixed> $dependencies The dependencies declared in the manifest.
	 *
	 * @return string[] One warning per unresolvable dependency.
	 */
	private function buildDependencyWarnings(\ZipArchive $zip, array $components, array $dependencies): array {
		$carried = $this->idsCarriedBy(zip: $zip, components: $components);
		$warnings = [];

		foreach ($dependencies as $dependency) {
			// Both shapes: a plain ref, and the `{type, name}` entry older
			// packages carry. An entry in neither shape is named as it stands
			// rather than reported as `unknown`, which said nothing.
			$ref = $dependency;
			if (is_array($dependency) === true) {
				$ref = ($dependency['id'] ?? $dependency['name'] ?? '');
			}

			$ref = trim((string)$ref);
			if ($ref === '' || in_array($ref, $carried, true) === true) {
				continue;
			}

			$warnings[] = "Dependency '{$ref}' is referenced by this package but not carried in it, "
				. 'so the target instance has to hold it already.';
		}

		return $warnings;
	}//end buildDependencyWarnings()


	/**
	 * Every object id the package's component files carry.
	 *
	 * @param \ZipArchive $zip The opened ZIP archive.
	 * @param array<mixed> $components The components declared in the manifest.
	 *
	 * @return array<int, string> The ids.
	 */
	private function idsCarriedBy(\ZipArchive $zip, array $components): array {
		$ids = [];

		foreach ($components as $component) {
			$content = $zip->getFromName((string)$component . '.json');
			if ($content === false) {
				continue;
			}

			$data = json_decode((string)$content, true);
			if (is_array($data) === false) {
				continue;
			}

			$this->collectIds(value: $data, ids: $ids);
		}

		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name === false || str_starts_with($name, 'workflows/') === false) {
				continue;
			}

			$data = json_decode((string)$zip->getFromIndex($i), true);
			if (is_array($data) === true) {
				$this->collectIds(value: $data, ids: $ids);
			}
		}

		return array_values(array_unique($ids));
	}//end idsCarriedBy()


	/**
	 * Collect every `id` and `@self.uuid` a decoded structure carries.
	 *
	 * @param mixed $value The decoded structure.
	 * @param array<int, string> $ids Collected ids, appended in place.
	 *
	 * @return void
	 */
	private function collectIds(mixed $value, array &$ids): void {
		if (is_array($value) === false) {
			return;
		}

		foreach (['id', 'uuid'] as $key) {
			$candidate = ($value[$key] ?? null);
			if (is_string($candidate) === true && trim($candidate) !== '') {
				$ids[] = trim($candidate);
			}
		}

		foreach ($value as $entry) {
			if (is_array($entry) === true) {
				$this->collectIds(value: $entry, ids: $ids);
			}
		}
	}//end collectIds()
}//end class
