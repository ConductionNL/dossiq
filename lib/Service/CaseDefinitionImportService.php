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

use OCA\Dossiq\Kernel\Contracts\CaseDefinitionImportServiceInterface;
use OCA\Dossiq\Service\CaseDefinition\PackageImport;
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
class CaseDefinitionImportService implements CaseDefinitionImportServiceInterface {

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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
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
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function importCaseDefinition(
		string $zipPath,
		string $strategy = 'skip',
	): array {
		$validation = $this->validator->validatePackage(zipPath: $zipPath);
		if ($validation['valid'] === false) {
			return $this->validationFailure(validation: $validation);
		}

		$zip = new ZipArchive();
		$zip->open($zipPath, ZipArchive::RDONLY);
		$results = $this->importComponents(
			import: new PackageImport(zip: $zip, strategy: $strategy),
			components: ($validation['manifest']['components'] ?? [])
		);
		$zip->close();

		return $this->importSummary(results: $results);
	}//end importCaseDefinition()

	/**
	 * The answer for a package that did not validate.
	 *
	 * @param array<string, mixed> $validation The validator's answer.
	 *
	 * @return array{success: bool, message: string, components: array<string, array{status: string, message: string}>}
	 */
	private function validationFailure(array $validation): array {
		return [
			'success' => false,
			'message' => 'Package validation failed: ' . implode('; ', $validation['errors']),
			'components' => [],
		];
	}//end validationFailure()

	/**
	 * Import every component the manifest names, in order.
	 *
	 * @param PackageImport     $import     The opened package and its strategy.
	 * @param array<int, mixed> $components The manifest's component names.
	 *
	 * @return array<string, array{status: string, message: string}> The result per component.
	 */
	private function importComponents(PackageImport $import, array $components): array {
		$results = [];
		foreach ($components as $component) {
			$results[$component] = $this->importOrReport(import: $import, component: $component);
		}

		return $results;
	}//end importComponents()

	/**
	 * Import one component; a failure becomes its error result and is logged.
	 *
	 * @param PackageImport $import    The opened package and its strategy.
	 * @param mixed         $component The component name.
	 *
	 * @return array{status: string, message: string}
	 */
	private function importOrReport(PackageImport $import, mixed $component): array {
		try {
			return $this->importComponent(import: $import, component: $component);
		} catch (\Throwable $e) {
			$this->logger->error(
				'Failed to import component {component}: {error}',
				[
					'component' => $component,
					'error' => $e->getMessage(),
				]
			);

			return $this->componentResult(status: 'error', message: $e->getMessage());
		}
	}//end importOrReport()

	/**
	 * Answer the import, after logging how it ended.
	 *
	 * @param array<string, array{status: string, message: string}> $results The result per component.
	 *
	 * @return array{success: bool, message: string, components: array<string, array{status: string, message: string}>}
	 */
	private function importSummary(array $results): array {
		$allSuccess = in_array('error', array_column($results, 'status'), true) === false;
		$this->logImportOutcome(allSuccess: $allSuccess, count: count($results));

		return [
			'success' => $allSuccess,
			'message' => $this->importMessage(allSuccess: $allSuccess),
			'components' => $results,
		];
	}//end importSummary()

	/**
	 * Log how the import ended.
	 *
	 * @param bool $allSuccess Whether every component was imported.
	 * @param int  $count      How many components were attempted.
	 *
	 * @return void
	 */
	private function logImportOutcome(bool $allSuccess, int $count): void {
		$successLabel = 'false';
		if ($allSuccess === true) {
			$successLabel = 'true';
		}

		$this->logger->info(
			'Case definition import completed: {success}, components: {count}',
			[
				'success' => $successLabel,
				'count' => $count,
			]
		);
	}//end logImportOutcome()

	/**
	 * The sentence the import answers with.
	 *
	 * @param bool $allSuccess Whether every component was imported.
	 *
	 * @return string
	 */
	private function importMessage(bool $allSuccess): string {
		if ($allSuccess === true) {
			return 'Import completed successfully';
		}

		return 'Import completed with errors';
	}//end importMessage()

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
	 * @param PackageImport $import    The opened package and its strategy.
	 * @param string        $component The component name.
	 *
	 * @return array{status: string, message: string, created?: array<int, string>, replaced?: array<int, string>}
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function importComponent(PackageImport $import, string $component): array {
		if ($component === 'workflows') {
			return $this->workflows->deploy(zip: $import->zip, strategy: $import->strategy);
		}

		$content = $import->zip->getFromName($component . '.json');
		$refusal = $this->unusableComponent(component: $component, content: $content);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->writer->writeCollections(
			component: $component,
			data: json_decode((string)$content, true),
			collections: self::COMPONENT_COLLECTIONS[$component],
			strategy: $import->strategy
		);
	}//end importComponent()

	/**
	 * Why a component cannot be written, or null when it can: its file is
	 * missing, it is not JSON, or it names no collection this import knows.
	 *
	 * @param string       $component The component name.
	 * @param string|false $content   The component file's content, or false when absent.
	 *
	 * @return array{status: string, message: string}|null
	 */
	private function unusableComponent(string $component, string|false $content): ?array {
		if ($content === false) {
			return $this->componentResult(status: 'skipped', message: "Component file {$component}.json not found in archive");
		}

		if (is_array(json_decode($content, true)) === false) {
			return $this->componentResult(status: 'error', message: "Invalid JSON in {$component}.json");
		}

		if ((self::COMPONENT_COLLECTIONS[$component] ?? []) === []) {
			return $this->componentResult(
				status: 'error',
				message: "Component '{$component}' names no collection this import knows how to write."
			);
		}

		return null;
	}//end unusableComponent()

	/**
	 * One component's result.
	 *
	 * @param string $status  `skipped` or `error`.
	 * @param string $message What happened.
	 *
	 * @return array{status: string, message: string}
	 */
	private function componentResult(string $status, string $message): array {
		return [
			'status' => $status,
			'message' => $message,
		];
	}//end componentResult()

}//end class
