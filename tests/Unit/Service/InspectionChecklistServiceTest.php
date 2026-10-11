<?php

/**
 * InspectionChecklistService Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\InspectionChecklistService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for InspectionChecklistService.
 *
 * @covers \OCA\Dossiq\Service\InspectionChecklistService
 * @uses \OCA\Dossiq\Service\Inspection\InspectionTemplateMapper
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
	/**
	 * Templates are read from the one template schema and answered flat.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function testListChecklistsReadsTheTemplateSchemaAndAnswersFlat(): void {
		$store = new class {
			/**
			 * The last search.
			 *
			 * @var array<int, mixed>
			 */
			public array $asked = [];

			/**
			 * Answer one template.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema slug.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>> The template.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->asked = [$register, $schema, $filters];

				return [['@self' => ['id' => 't-1'], 'name' => 'Fundering', 'status' => 'active', 'sections' => [['items' => [['id' => 'q1', 'label' => 'Wapening', 'responseType' => 'yes_no_na']]]]]];
			}
		};
		$this->settingsService->method('getObjectService')->willReturn($store);
		$this->settingsService->method('getConfigValue')->willReturn('7');

		$result = $this->service->listChecklists(caseTypeRef: 'ct-1');

		$this->assertSame('inspectionChecklistTemplate', $store->asked[1]);
		$this->assertSame('ct-1', $store->asked[2]['caseType']);
		$this->assertSame('t-1', $result[0]['id']);
		$this->assertTrue($result[0]['active']);
		$this->assertSame('Wapening', $result[0]['items'][0]['question']);
	}//end testListChecklistsReadsTheTemplateSchemaAndAnswersFlat()

	/**
	 * A created checklist is saved as a template with its items in a section.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function testCreateChecklistSavesATemplate(): void {
		$store = new class {
			/**
			 * What was saved, and where.
			 *
			 * @var array<int, mixed>
			 */
			public array $saved = [];

			/**
			 * Record and echo the save.
			 *
			 * @param array<string, mixed> $object   The object.
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema slug.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed> The object.
			 */
			public function saveObject(array $object, int|string $register, int|string $schema, ?string $uuid = null): array {
				$this->saved = [$object, $schema, $uuid];

				return $object;
			}
		};
		$this->settingsService->method('getObjectService')->willReturn($store);
		$this->settingsService->method('getConfigValue')->willReturn('7');

		$result = $this->service->createChecklist(data: ['name' => 'Fundering', 'caseTypeRef' => 'ct-1', 'items' => [['question' => 'Wapening', 'type' => 'boolean']]]);

		$this->assertSame('inspectionChecklistTemplate', $store->saved[1]);
		$this->assertNull($store->saved[2]);
		$this->assertSame('Wapening', $store->saved[0]['sections'][0]['items'][0]['label']);
		$this->assertSame(1, $store->saved[0]['version']);
		$this->assertSame('Wapening', $result['items'][0]['question']);
	}//end testCreateChecklistSavesATemplate()
}//end class
