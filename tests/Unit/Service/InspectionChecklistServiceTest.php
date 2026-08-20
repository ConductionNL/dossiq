<?php

/**
 * InspectionChecklistService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\InspectionChecklistService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for InspectionChecklistService.
 *
 * @covers \OCA\Procest\Service\InspectionChecklistService
 */
class InspectionChecklistServiceTest extends TestCase {

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var InspectionChecklistService
	 */
	private InspectionChecklistService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new InspectionChecklistService(
			settingsService: $this->settingsService,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * Test that listChecklists returns empty array when OpenRegister unavailable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function testListChecklistsReturnsEmptyWhenNoOpenRegister(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$result = $this->service->listChecklists();

		$this->assertSame(expected: [], actual: $result);
	}//end testListChecklistsReturnsEmptyWhenNoOpenRegister()

	/**
	 * Test that createChecklist throws when OpenRegister unavailable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function testCreateChecklistThrowsWhenNoOpenRegister(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$this->service->createChecklist(data: ['name' => 'Test', 'caseTypeRef' => 'abc']);
	}//end testCreateChecklistThrowsWhenNoOpenRegister()

	/**
	 * Test that submitResult throws when OpenRegister unavailable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function testSubmitResultThrowsWhenNoOpenRegister(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$this->service->submitResult(
			caseId: 'case-uuid',
			checklistId: 'checklist-uuid',
			resultData: ['answers' => []],
			completedBy: 'user1'
		);
	}//end testSubmitResultThrowsWhenNoOpenRegister()

	/**
	 * Test that getResultsForCase returns empty array when OpenRegister unavailable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function testGetResultsForCaseReturnsEmptyWhenNoOpenRegister(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$result = $this->service->getResultsForCase(caseId: 'case-uuid');

		$this->assertSame(expected: [], actual: $result);
	}//end testGetResultsForCaseReturnsEmptyWhenNoOpenRegister()

	/**
	 * Test that deleteChecklist returns false when OpenRegister unavailable.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function testDeleteChecklistReturnsFalseWhenNoOpenRegister(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$result = $this->service->deleteChecklist(id: 'some-uuid');

		$this->assertFalse(condition: $result);
	}//end testDeleteChecklistReturnsFalseWhenNoOpenRegister()
}//end class
