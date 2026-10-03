<?php

/**
 * Dossiq case definition package validator.
 *
 * Reads a package without importing it and says what is wrong with it: the
 * files it does not carry, the manifest fields it leaves out, the components
 * whose json will not parse, and the ids this instance already holds.
 *
 * Split from {@see \OCA\Dossiq\Service\CaseDefinitionImportService}, which
 * orchestrates an import. Validating is the half a caller may run on its own,
 * and it writes nothing.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Reads a case definition package and says what is wrong with it.
 *
 * @spec openspec/specs/case-types/spec.md
 */
class PackageValidator {
	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger What a validation writes about itself.
	 * @param PackageIds      $ids    Every identifier the package carries.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly PackageIds $ids,
	) {
	}//end __construct()

	/**
	 * Required files in an import package.
	 *
	 * @var string[]
	 */
	private const REQUIRED_FILES = ['manifest.json'];

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
		$carried = $this->ids->idsCarriedBy(zip: $zip, components: $components);
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


}//end class
