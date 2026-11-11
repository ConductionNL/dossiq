<?php

/**
 * CaseDefinitionController export / validate / import unit tests.
 *
 * Pins every answer of the package endpoints: the missing parameter or file,
 * the refused strategy, the download, the service's own 400, and the logged
 * 500 for anything else.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Controller\CaseDefinitionController;
use OCA\Dossiq\Service\CaseDefinitionExportService;
use OCA\Dossiq\Service\CaseDefinitionImportService;
use OCA\Dossiq\Service\CaseTypeCopyService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The package endpoints answer as they always did.
 *
 * @covers \OCA\Dossiq\Controller\CaseDefinitionController
 */
class CaseDefinitionTransferTest extends TestCase {

	/**
	 * The request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The export service.
	 *
	 * @var CaseDefinitionExportService&MockObject
	 */
	private CaseDefinitionExportService&MockObject $exportService;

	/**
	 * The import service.
	 *
	 * @var CaseDefinitionImportService&MockObject
	 */
	private CaseDefinitionImportService&MockObject $importService;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->exportService = $this->createMock(CaseDefinitionExportService::class);
		$this->importService = $this->createMock(CaseDefinitionImportService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The controller.
	 *
	 * @return CaseDefinitionController
	 */
	private function controller(): CaseDefinitionController {
		return new CaseDefinitionController(
			appName: 'dossiq',
			request: $this->request,
			exportService: $this->exportService,
			importService: $this->importService,
			copyService: $this->createMock(CaseTypeCopyService::class),
			logger: $this->logger,
		);
	}//end controller()

	/**
	 * Answer request parameters from a map.
	 *
	 * @param array<string, mixed> $params The parameters.
	 *
	 * @return void
	 */
	private function params(array $params): void {
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key, mixed $default = null): mixed => ($params[$key] ?? $default)
		);
	}//end params()

	/**
	 * An export without a case type is a 400 and exports nothing.
	 *
	 * @return void
	 */
	public function testAnExportWithoutACaseTypeIsABadRequest(): void {
		$this->params([]);
		$this->exportService->expects($this->never())->method('exportCaseDefinition');

		$response = $this->controller()->export();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Parameter caseTypeId is required'], $response->getData());
	}//end testAnExportWithoutACaseTypeIsABadRequest()

	/**
	 * An export downloads the archive, decodes JSON-string components, and removes the file.
	 *
	 * @return void
	 */
	public function testAnExportDownloadsTheArchiveAndRemovesTheFile(): void {
		$path = tempnam(sys_get_temp_dir(), 'cdexport');
		file_put_contents($path, 'PK-zip-bytes');
		$this->params(['caseTypeId' => 'ct-1', 'components' => '["phases","roles"]']);
		$this->exportService->expects($this->once())->method('exportCaseDefinition')
			->with('ct-1', ['phases', 'roles'])
			->willReturn(['path' => $path, 'filename' => 'vergunning.zip']);

		$response = $this->controller()->export();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame('PK-zip-bytes', $response->render());
		$this->assertSame('application/zip', $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString('vergunning.zip', $response->getHeaders()['Content-Disposition']);
		$this->assertFileDoesNotExist($path);
	}//end testAnExportDownloadsTheArchiveAndRemovesTheFile()

	/**
	 * Components that are not JSON become an empty list.
	 *
	 * @return void
	 */
	public function testComponentsThatAreNotJsonBecomeAnEmptyList(): void {
		$path = tempnam(sys_get_temp_dir(), 'cdexport');
		file_put_contents($path, 'x');
		$this->params(['caseTypeId' => 'ct-1', 'components' => 'not json']);
		$this->exportService->expects($this->once())->method('exportCaseDefinition')
			->with('ct-1', [])
			->willReturn(['path' => $path, 'filename' => 'x.zip']);

		$this->controller()->export();
	}//end testComponentsThatAreNotJsonBecomeAnEmptyList()

	/**
	 * The export service's own refusal is a 400 with its message; anything else a logged 500.
	 *
	 * @return void
	 */
	public function testExportFailuresAnswerTheirStatus(): void {
		$this->params(['caseTypeId' => 'ct-1']);
		$this->exportService->method('exportCaseDefinition')->willReturnOnConsecutiveCalls(
			$this->throwException(new InvalidArgumentException('Unknown component: x')),
			$this->throwException(new RuntimeException('disk full'))
		);
		$this->logger->expects($this->once())->method('error')->with('Case definition export failed: disk full');

		$refused = $this->controller()->export();
		$failed = $this->controller()->export();

		$this->assertSame([Http::STATUS_BAD_REQUEST, ['error' => 'Unknown component: x']], [$refused->getStatus(), $refused->getData()]);
		$this->assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Export failed: disk full']], [$failed->getStatus(), $failed->getData()]);
	}//end testExportFailuresAnswerTheirStatus()

	/**
	 * Validate and import refuse a request without an uploaded package.
	 *
	 * @return void
	 */
	public function testWithoutAPackageValidateAndImportRefuse(): void {
		$this->params([]);
		$this->request->method('getUploadedFile')->willReturnOnConsecutiveCalls(null, ['name' => 'no-tmp.zip'], null);
		$this->importService->expects($this->never())->method('validatePackage');
		$this->importService->expects($this->never())->method('importCaseDefinition');

		foreach ([$this->controller()->validate(), $this->controller()->validate(), $this->controller()->import()] as $response) {
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
			$this->assertSame(['error' => 'No package file uploaded'], $response->getData());
		}
	}//end testWithoutAPackageValidateAndImportRefuse()

	/**
	 * Validate answers the validator's report, and a failure is a logged 500.
	 *
	 * @return void
	 */
	public function testValidateAnswersTheReport(): void {
		$this->request->method('getUploadedFile')->willReturn(['tmp_name' => '/tmp/p.zip']);
		$this->importService->method('validatePackage')->willReturnOnConsecutiveCalls(
			['valid' => true, 'errors' => []],
			$this->throwException(new RuntimeException('corrupt zip'))
		);
		$this->logger->expects($this->once())->method('error')->with('Case definition validation failed: corrupt zip');

		$valid = $this->controller()->validate();
		$failed = $this->controller()->validate();

		$this->assertSame([Http::STATUS_OK, ['valid' => true, 'errors' => []]], [$valid->getStatus(), $valid->getData()]);
		$this->assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Validation failed: corrupt zip']], [$failed->getStatus(), $failed->getData()]);
	}//end testValidateAnswersTheReport()

	/**
	 * An unknown strategy is refused before anything is imported.
	 *
	 * @return void
	 */
	public function testAnUnknownStrategyIsRefused(): void {
		$this->params(['strategy' => 'replace']);
		$this->request->method('getUploadedFile')->willReturn(['tmp_name' => '/tmp/p.zip']);
		$this->importService->expects($this->never())->method('importCaseDefinition');

		$response = $this->controller()->import();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'Invalid strategy. Must be: skip, overwrite, or merge'], $response->getData());
	}//end testAnUnknownStrategyIsRefused()

	/**
	 * An import answers 200 when it succeeded, 422 when it did not, and a logged 500 when it threw; skip is the default.
	 *
	 * @return void
	 */
	public function testAnImportAnswersItsOutcome(): void {
		$this->params([]);
		$this->request->method('getUploadedFile')->willReturn(['tmp_name' => '/tmp/p.zip']);
		$this->importService->expects($this->exactly(3))->method('importCaseDefinition')
			->with('/tmp/p.zip', 'skip')
			->willReturnOnConsecutiveCalls(
				['success' => true, 'created' => 3],
				['success' => false, 'errors' => ['x']],
				$this->throwException(new RuntimeException('register down'))
			);
		$this->logger->expects($this->once())->method('error')->with('Case definition import failed: register down');

		$done = $this->controller()->import();
		$refused = $this->controller()->import();
		$failed = $this->controller()->import();

		$this->assertSame([Http::STATUS_OK, ['success' => true, 'created' => 3]], [$done->getStatus(), $done->getData()]);
		$this->assertSame([Http::STATUS_UNPROCESSABLE_ENTITY, ['success' => false, 'errors' => ['x']]], [$refused->getStatus(), $refused->getData()]);
		$this->assertSame([Http::STATUS_INTERNAL_SERVER_ERROR, ['error' => 'Import failed: register down']], [$failed->getStatus(), $failed->getData()]);
	}//end testAnImportAnswersItsOutcome()
}//end class
