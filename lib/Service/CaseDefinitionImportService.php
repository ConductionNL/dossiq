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

use OCA\Dossiq\Service\CaseDefinition\PackageValidator;
use OCA\Dossiq\Service\CaseDefinition\PackageWriter;
use OCA\Dossiq\Service\CaseDefinition\WorkflowDeployer;
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
	 * @param LoggerInterface  $logger    The logger instance.
	 * @param PackageWriter    $writer    Writes the package's collection rows. dossiq
	 *                                    stores nothing of its own here: the objects are
	 *                                    OpenRegister's and are written the way the rest
	 *                                    of the app writes them (ADR-022), which is why
	 *                                    the register and schema names now live with the
	 *                                    writer rather than here.
	 * @param WorkflowDeployer $workflows Deploys the package's workflow templates.
	 * @param PackageValidator $validator Reads a package and says what is wrong with it.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly PackageWriter $writer,
		private readonly WorkflowDeployer $workflows,
		private readonly PackageValidator $validator,
	) {
	}//end __construct()

	/**
	 * Validate a case definition package without importing it.
	 *
	 * Kept here so a caller that has the import service does not also need the
	 * validator: the reading itself lives in {@see PackageValidator}.
	 *
	 * @param string $zipPath Path to the uploaded ZIP file.
	 *
	 * @return array{valid: bool, errors: string[], warnings: string[], manifest: ?array<string, mixed>, conflicts: array<string, mixed>}
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function validatePackage(string $zipPath): array {
		return $this->validator->validatePackage(zipPath: $zipPath);
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
		$validation = $this->validator->validatePackage(zipPath: $zipPath);
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
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function importComponent(
		\ZipArchive $zip,
		string $component,
		string $strategy,
	): array {
		if ($component === 'workflows') {
			return $this->workflows->deploy(zip: $zip, strategy: $strategy);
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































}//end class
