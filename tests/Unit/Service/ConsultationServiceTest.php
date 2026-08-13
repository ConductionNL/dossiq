<?php

/**
 * ConsultationService Unit Tests
 *
 * Tests for the Procest ConsultationService covering CRUD, status machine,
 * dependency cycle validation, blocking consultations, and extension requests.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-02
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\AdviceDelegationService;
use OCA\Procest\Service\Consultation\ConsultationDependencyGraph;
use OCA\Procest\Service\Consultation\ConsultationRepository;
use OCA\Procest\Service\ConsultationService;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ObjectService stub matching the named-arg signatures used in ConsultationService.
 */
interface ConsultationObjectServiceStub {

	/**
	 * Search objects by register/schema slug.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array $filters The query filters.
	 *
	 * @return array
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters): array;

	/**
	 * Search objects by numeric @self query.
	 *
	 * @param array $query The query payload.
	 *
	 * @return array
	 */
	public function searchObjects(array $query): array;

	/**
	 * Find a single object by id.
	 *
	 * @param string $id The object id.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 *
	 * @return array
	 */
	public function find(string $id, string $register, string $schema): array;

	/**
	 * Save or update an object.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param array $data The object payload.
	 * @param string $id Optional id for update.
	 *
	 * @return object
	 */
	public function saveObject(string $register, string $schema, array $data, string $id = ''): object;

	/**
	 * Delete an object by id.
	 *
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 *
	 * @return void
	 */
	public function deleteObject(string $register, string $schema, string $id): void;
}//end interface

/**
 * Unit tests for ConsultationService.
 *
 * @covers \OCA\Procest\Service\ConsultationService
 *
 * @uses \OCA\Procest\Service\Consultation\ConsultationDependencyGraph
 * @uses \OCA\Procest\Service\Consultation\ConsultationRepository
 */
class ConsultationServiceTest extends TestCase {

	/**
	 * Mocked SettingsService.
	 *
	 * @var SettingsService|MockObject
	 */
	private SettingsService $settings;

	/**
	 * Mocked LoggerInterface.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Mocked AdviceDelegationService.
	 *
	 * @var AdviceDelegationService|MockObject
	 */
	private AdviceDelegationService $adviceDelegation;

	/**
	 * Service under test.
	 *
	 * @var ConsultationService
	 */
	private ConsultationService $service;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->adviceDelegation = $this->createMock(AdviceDelegationService::class);
		// The repository and dependency graph are real collaborators, not
		// mocks: every assertion below is about behaviour they inherited
		// verbatim from ConsultationService, and the repository is still
		// driven entirely by the mocked SettingsService.
		$repository = new ConsultationRepository($this->settings, $this->logger);
		$this->service = new ConsultationService(
			settingsService: $this->settings,
			logger: $this->logger,
			adviceDelegation: $this->adviceDelegation,
			repository: $repository,
			dependencyGraph: new ConsultationDependencyGraph($repository),
		);

	}//end setUp()

	/**
	 * createConsultation throws RuntimeException when ObjectService is unavailable.
	 *
	 * @return void
	 */
	public function testCreateConsultationFailsWithoutObjectService(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$this->service->createConsultation(data: [
			'parentCase' => 'zaak-uuid',
			'adviesAuthority' => 'Brandweer',
			'questionFormulation' => 'Is het brandveilig?',
			'latestResponseDate' => '2026-07-01',
		]);

	}//end testCreateConsultationFailsWithoutObjectService()

	/**
	 * createConsultation throws RuntimeException when schema config is missing.
	 *
	 * @return void
	 */
	public function testCreateConsultationFailsWhenSchemaNotConfigured(): void {
		$objectService = $this->createMock(ConsultationObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')
			->willReturnMap([
				['register', ''],
				['consultation_schema', ''],
			]);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Consultation schema not configured');

		$this->service->createConsultation(data: [
			'parentCase' => 'zaak-uuid',
			'adviesAuthority' => 'Brandweer',
			'questionFormulation' => 'Is het brandveilig?',
			'latestResponseDate' => '2026-07-01',
		]);

	}//end testCreateConsultationFailsWhenSchemaNotConfigured()

	/**
	 * createConsultation throws RuntimeException when parentZaak is missing.
	 *
	 * @return void
	 */
	public function testCreateConsultationFailsWhenParentZaakMissing(): void {
		$objectService = $this->createMock(ConsultationObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')->willReturn('some-id');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('parentZaak is required');

		$this->service->createConsultation(data: [
			'adviesAuthority' => 'Brandweer',
			'questionFormulation' => 'Is het brandveilig?',
			'latestResponseDate' => '2026-07-01',
		]);

	}//end testCreateConsultationFailsWhenParentZaakMissing()

	/**
	 * updateStatus throws RuntimeException for an unrecognized status.
	 *
	 * @return void
	 */
	public function testUpdateStatusRejectsInvalidStatus(): void {
		$objectService = $this->createMock(ConsultationObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')->willReturn('some-id');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Invalid status: ongeldig');

		$this->service->updateStatus(consultationId: 'con-uuid', newStatus: 'ongeldig');

	}//end testUpdateStatusRejectsInvalidStatus()

	/**
	 * submitResponse throws RuntimeException for an unrecognized advice type.
	 *
	 * @return void
	 */
	public function testSubmitResponseRejectsInvalidAdvice(): void {
		$objectService = $this->createMock(ConsultationObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')->willReturn('some-id');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Invalid advice type: onbekend');

		$this->service->submitResponse(
			consultationId: 'con-uuid',
			response: ['advies' => 'onbekend'],
		);

	}//end testSubmitResponseRejectsInvalidAdvice()

	/**
	 * getBlockingConsultations returns only mandatory non-terminal consultations.
	 *
	 * @return void
	 */
	public function testGetBlockingConsultationsReturnsOnlyMandatoryOpen(): void {
		$objectService = $this->createMock(ConsultationObjectServiceStub::class);

		$consultations = [
			['id' => 'c1', 'mandatory' => true,  'status' => 'open'],
			['id' => 'c2', 'mandatory' => true,  'status' => 'advies_uitgebracht'],
			['id' => 'c3', 'mandatory' => false, 'status' => 'open'],
			['id' => 'c4', 'mandatory' => true,  'status' => 'afgesloten'],
		];

		$objectService->method('searchObjectsBySlug')->willReturn($consultations);

		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')->willReturn('some-id');

		$blocking = $this->service->getBlockingConsultations(caseId: 'zaak-uuid');

		$this->assertCount(1, $blocking);
		$this->assertSame('c1', $blocking[0]['id']);

	}//end testGetBlockingConsultationsReturnsOnlyMandatoryOpen()

	/**
	 * validateDependencyCycle returns true when the ID is a direct self-reference.
	 *
	 * @return void
	 */
	public function testValidateDependencyCycleDetectsSelfReference(): void {
		$result = $this->service->validateDependencyCycle(
			consultationId: 'con-uuid',
			dependsOn: ['con-uuid'],
		);

		$this->assertTrue($result);

	}//end testValidateDependencyCycleDetectsSelfReference()

	/**
	 * validateDependencyCycle returns false for an empty dependsOn list.
	 *
	 * @return void
	 */
	public function testValidateDependencyCycleReturnsFalseForEmptyList(): void {
		$result = $this->service->validateDependencyCycle(
			consultationId: 'con-uuid',
			dependsOn: [],
		);

		$this->assertFalse($result);

	}//end testValidateDependencyCycleReturnsFalseForEmptyList()

	/**
	 * approveExtension throws RuntimeException for an invalid date format.
	 *
	 * @return void
	 */
	public function testApproveExtensionRejectsInvalidDateFormat(): void {
		$objectService = $this->createMock(ConsultationObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($objectService);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Invalid date format');

		$this->service->approveExtension(
			consultationId: 'con-uuid',
			newDeadline: '01-07-2026',
		);

	}//end testApproveExtensionRejectsInvalidDateFormat()

}//end class
