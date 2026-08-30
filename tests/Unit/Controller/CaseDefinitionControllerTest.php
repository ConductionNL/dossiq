<?php

/**
 * CaseDefinitionController Unit Tests (zaaktype-copy)
 *
 * Covers the `copy()` and `delete()` REST surface added by the
 * zaaktype-copy change: guard-reason to HTTP-status mapping
 * (not_found => 404, published => 409) and the happy paths.
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
 *
 * @spec openspec/changes/zaaktype-copy/tasks.md#T14
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseDefinitionController;
use OCA\Dossiq\Service\CaseDefinitionExportService;
use OCA\Dossiq\Service\CaseDefinitionImportService;
use OCA\Dossiq\Service\CaseTypeCopyService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CaseDefinitionController::copy() and ::delete().
 *
 * @covers \OCA\Dossiq\Controller\CaseDefinitionController
 */
class CaseDefinitionControllerTest extends TestCase {

	/**
	 * @var CaseTypeCopyService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private CaseTypeCopyService $copyService;

	/**
	 * The controller under test.
	 *
	 * @var CaseDefinitionController
	 */
	private CaseDefinitionController $controller;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$exportService = $this->createMock(CaseDefinitionExportService::class);
		$importService = $this->createMock(CaseDefinitionImportService::class);
		$this->copyService = $this->createMock(CaseTypeCopyService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->controller = new CaseDefinitionController(
			appName: 'dossiq',
			request: $request,
			exportService: $exportService,
			importService: $importService,
			copyService: $this->copyService,
			logger: $logger,
		);
	}//end setUp()

	/**
	 * copy() returns 200 with the new case type on success.
	 *
	 * @return void
	 */
	public function testCopyReturnsNewCaseType(): void {
		$newCaseType = ['id' => 'new-uuid', 'title' => 'Copy of Omgevingsvergunning'];
		$this->copyService->method('copy')->with('source-uuid')->willReturn($newCaseType);

		$response = $this->controller->copy('source-uuid');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($newCaseType, $response->getData());
	}//end testCopyReturnsNewCaseType()

	/**
	 * copy() returns 404 when the source case type does not resolve.
	 *
	 * @return void
	 */
	public function testCopyReturns404WhenSourceMissing(): void {
		$this->copyService->method('copy')->with('missing')->willReturn(null);

		$response = $this->controller->copy('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}//end testCopyReturns404WhenSourceMissing()

	/**
	 * delete() returns 200 on a successful draft delete.
	 *
	 * @return void
	 */
	public function testDeleteReturns200OnSuccess(): void {
		$this->copyService->method('deleteDraft')->with('draft-uuid')->willReturn(['ok' => true]);

		$response = $this->controller->delete('draft-uuid');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}//end testDeleteReturns200OnSuccess()

	/**
	 * delete() returns 404 when the case type does not resolve.
	 *
	 * @return void
	 */
	public function testDeleteReturns404WhenNotFound(): void {
		$this->copyService->method('deleteDraft')->willReturn(['ok' => false, 'reason' => 'not_found']);

		$response = $this->controller->delete('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testDeleteReturns404WhenNotFound()

	/**
	 * delete() returns 409 when the case type is published.
	 *
	 * @return void
	 */
	public function testDeleteReturns409WhenPublished(): void {
		$this->copyService->method('deleteDraft')->willReturn(['ok' => false, 'reason' => 'published']);

		$response = $this->controller->delete('published-uuid');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}//end testDeleteReturns409WhenPublished()
}//end class
