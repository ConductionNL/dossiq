<?php

/**
 * Dossiq Case Definition Controller
 *
 * Handles API endpoints for exporting and importing case type definitions
 * as portable ZIP archives for DTAP pipeline deployment.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/specs/case-types/spec.md
 * @spec openspec/changes/zaaktype-copy/tasks.md#T06
 * @spec openspec/changes/zaaktype-copy/tasks.md#T07
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseDefinitionExportService;
use OCA\Dossiq\Service\CaseDefinitionImportService;
use OCA\Dossiq\Service\CaseTypeCopyService;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Controller for case definition export/import operations.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/zaaktype-copy/tasks.md#T06
 */
class CaseDefinitionController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request object.
	 * @param CaseDefinitionExportService $exportService The export service.
	 * @param CaseDefinitionImportService $importService The import service.
	 * @param CaseTypeCopyService $copyService The copy/guarded-delete service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseDefinitionExportService $exportService,
		private readonly CaseDefinitionImportService $importService,
		private readonly CaseTypeCopyService $copyService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Export a case definition as a ZIP archive.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function export(): DataDownloadResponse|JSONResponse {
		try {
			$caseTypeId = $this->request->getParam('caseTypeId', '');
			if (empty($caseTypeId) === true) {
				return new JSONResponse(
					['error' => 'Parameter caseTypeId is required'],
					Http::STATUS_BAD_REQUEST
				);
			}

			return $this->exportArchive(caseTypeId: $caseTypeId, components: $this->requestedComponents());
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(
				['error' => $e->getMessage()],
				Http::STATUS_BAD_REQUEST
			);
		} catch (\Throwable $e) {
			return $this->failure(action: 'export', exception: $e);
		}//end try
	}//end export()

	/**
	 * Validate a case definition package without importing it.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function validate(): JSONResponse {
		try {
			$package = $this->uploadedPackage();
			if ($package === null) {
				return $this->noPackage();
			}

			return new JSONResponse($this->importService->validatePackage($package));
		} catch (\Throwable $e) {
			return $this->failure(action: 'validation', exception: $e);
		}
	}//end validate()

	/**
	 * Import a case definition package.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function import(): JSONResponse {
		try {
			$package = $this->uploadedPackage();
			$strategy = $this->request->getParam('strategy', 'skip');

			if ($package === null) {
				return $this->noPackage();
			}

			if (in_array($strategy, ['skip', 'overwrite', 'merge'], true) === false) {
				return new JSONResponse(
					['error' => 'Invalid strategy. Must be: skip, overwrite, or merge'],
					Http::STATUS_BAD_REQUEST
				);
			}

			return $this->importedPackage(package: $package, strategy: $strategy);
		} catch (\Throwable $e) {
			return $this->failure(action: 'import', exception: $e);
		}//end try
	}//end import()

	/**
	 * The components the request asks for; a JSON string is decoded, and one that is not JSON is none.
	 *
	 * @return mixed
	 */
	private function requestedComponents(): mixed {
		$components = $this->request->getParam('components', []);
		if (is_string($components) === true) {
			return json_decode($components, true) ?? [];
		}

		return $components;
	}//end requestedComponents()

	/**
	 * Export the case type and hand the archive over, removing the file it was written to.
	 *
	 * @param mixed $caseTypeId The case type.
	 * @param mixed $components The components to include.
	 *
	 * @return DataDownloadResponse|JSONResponse
	 */
	private function exportArchive(mixed $caseTypeId, mixed $components): DataDownloadResponse|JSONResponse {
		$result = $this->exportService->exportCaseDefinition($caseTypeId, $components);

		$content = file_get_contents($result['path']);
		unlink($result['path']);

		if ($content === false) {
			return new JSONResponse(
				['error' => 'Failed to read export file'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}

		return new DataDownloadResponse(
			$content,
			$result['filename'],
			'application/zip'
		);
	}//end exportArchive()

	/**
	 * The uploaded package's temporary path, or null when no package was uploaded.
	 *
	 * @return string|null
	 */
	private function uploadedPackage(): ?string {
		$file = $this->request->getUploadedFile('package');
		if (is_array($file) === false || isset($file['tmp_name']) === false) {
			return null;
		}

		return $file['tmp_name'];
	}//end uploadedPackage()

	/**
	 * The 400 for a request without a package.
	 *
	 * @return JSONResponse
	 */
	private function noPackage(): JSONResponse {
		return new JSONResponse(
			['error' => 'No package file uploaded'],
			Http::STATUS_BAD_REQUEST
		);
	}//end noPackage()

	/**
	 * Import the package and answer 200 when it succeeded, 422 when it did not.
	 *
	 * @param string $package  The package's temporary path.
	 * @param mixed  $strategy How existing objects are treated.
	 *
	 * @return JSONResponse
	 */
	private function importedPackage(string $package, mixed $strategy): JSONResponse {
		$result = $this->importService->importCaseDefinition(
			$package,
			$strategy
		);

		$statusCode = Http::STATUS_UNPROCESSABLE_ENTITY;
		if ($result['success'] === true) {
			$statusCode = Http::STATUS_OK;
		}

		return new JSONResponse($result, $statusCode);
	}//end importedPackage()

	/**
	 * The logged 500 a failed package call answers with.
	 *
	 * @param string     $action    `export`, `validation` or `import`.
	 * @param \Throwable $exception The failure.
	 *
	 * @return JSONResponse
	 */
	private function failure(string $action, \Throwable $exception): JSONResponse {
		$this->logger->error('Case definition ' . $action . ' failed: ' . $exception->getMessage());
		return new JSONResponse(
			['error' => ucfirst($action) . ' failed: ' . $exception->getMessage()],
			Http::STATUS_INTERNAL_SERVER_ERROR
		);
	}//end failure()

	/**
	 * Deep-copy a case type into a new draft.
	 *
	 * @param string $id The case type id to copy.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/zaaktype-copy/tasks.md#T06
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function copy(string $id): JSONResponse {
		try {
			$copy = $this->copyService->copy($id);

			if ($copy === null) {
				return new JSONResponse(
					['error' => 'Case type not found'],
					Http::STATUS_NOT_FOUND
				);
			}

			return new JSONResponse($copy);
		} catch (\Throwable $e) {
			$this->logger->error('Case type copy failed: ' . $e->getMessage());
			return new JSONResponse(
				['error' => 'Copy failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end copy()

	/**
	 * Start the next version of a case type.
	 *
	 * @param string $id The case type id to make the next version of.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function newVersion(string $id): JSONResponse {
		try {
			$version = $this->copyService->newVersion($id);

			if ($version === null) {
				return new JSONResponse(
					['error' => 'Case type not found'],
					Http::STATUS_NOT_FOUND
				);
			}

			return new JSONResponse($version);
		} catch (\Throwable $e) {
			$this->logger->error('Case type new version failed: ' . $e->getMessage());
			return new JSONResponse(
				['error' => 'New version failed: ' . $e->getMessage()],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end newVersion()

}//end class
