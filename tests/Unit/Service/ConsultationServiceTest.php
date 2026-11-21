<?php

/**
 * ConsultationService Unit Tests
 *
 * Tests for the Dossiq ConsultationService covering CRUD, status machine,
 * dependency cycle validation, blocking consultations, and extension requests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Consultation\ConsultationRecords;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\Consultation\ConsultationDependencyGraph;
use OCA\Dossiq\Service\Consultation\ConsultationRepository;
use OCA\Dossiq\Service\Obligations\ObligationDeclaration;
use OCA\Dossiq\Service\Obligations\ObligationService;
use OCA\Dossiq\Service\ConsultationService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;

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
	public function find(string $id, ?ObjectFindOptions $options = null, bool $_rbac = true, bool $_multitenancy = true): array;

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
 * @covers \OCA\Dossiq\Service\ConsultationService
 * @covers \OCA\Dossiq\Service\Consultation\ConsultationRecords
 * @uses \OCA\Dossiq\Service\Consultation\ConsultationDependencyGraph
 * @uses \OCA\Dossiq\Service\Consultation\ConsultationRepository
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Obligations\ObligationService
 * @uses   \OCA\Dossiq\Service\Obligations\ObligationDeclaration
 * @uses \OCA\Dossiq\Service\Consultation\ConsultationTerms
 * @uses \OCA\Dossiq\Service\Consultation\ConsultationStatusRules
 * @uses \OCA\Dossiq\Service\Support\ConfiguredSchemaRows
 */
class ConsultationServiceTest extends TestCase {
	use MakesCaseDateNormaliser;


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
			records: new ConsultationRecords(
				repository: $repository,
				dependencyGraph: new ConsultationDependencyGraph(repository: $repository),
				// A REAL ObligationService over the same mocked SettingsService,
				// not a double. Every assertion in this file is about behaviour
				// the advice request keeps now that the blocking is one declared
				// mechanism, and a double would let that behaviour be asserted
				// against a stub of itself.
				obligations: new ObligationService(
					settingsService: $this->settings,
					declaration: new ObligationDeclaration(),
					dates: $this->caseDates(),
					logger: $this->logger,
				),
			),
			dates: $this->caseDates(),
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
			'adviceAuthority' => 'Brandweer',
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
			'adviceAuthority' => 'Brandweer',
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
			'adviceAuthority' => 'Brandweer',
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
		$this->expectExceptionMessage('Invalid advice type: unknown');

		$this->service->submitResponse(
			consultationId: 'con-uuid',
			response: ['advice' => 'unknown'],
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
			['id' => 'c2', 'mandatory' => true,  'status' => 'advice_uitgebracht'],
			['id' => 'c3', 'mandatory' => false, 'status' => 'open'],
			['id' => 'c4', 'mandatory' => true,  'status' => 'closed'],
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

	/**
	 * A move the declared status graph does not allow is refused, naming both statuses.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAMoveTheStatusGraphDoesNotAllowIsRefused(): void {
		$objectService = $this->createMock(ConsultationObjectServiceStub::class);
		$objectService->method('find')->willReturn(['id' => 'con-uuid', 'status' => 'closed']);
		$objectService->expects($this->never())->method('saveObject');
		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')->willReturn('some-id');

		$this->expectException(exception: \RuntimeException::class);
		$this->expectExceptionMessage(message: 'Invalid status transition: closed -> open');

		$this->service->updateStatus(consultationId: 'con-uuid', newStatus: 'open');

	}//end testAMoveTheStatusGraphDoesNotAllowIsRefused()

	/**
	 * A stored status outside the graph does not constrain the move, and closing stamps closedAt.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAStoredStatusOutsideTheGraphDoesNotConstrainTheMove(): void {
		$objectService = new class {

			/**
			 * The patches written, in order.
			 *
			 * @var array<int, ObjectPatch>
			 */
			public array $patches = [];

			/**
			 * The stored consultation.
			 *
			 * @param string                 $id      The object id.
			 * @param ObjectFindOptions|null $options Ignored.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, ?ObjectFindOptions $options = null): array {
				return ['id' => $id, 'status' => 'legacy'];
			}

			/**
			 * Record a partial write.
			 *
			 * @param ObjectPatch $patch The patch.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(ObjectPatch $patch): array {
				$this->patches[] = $patch;
				return ['id' => $patch->objectId] + $patch->data;
			}

			/**
			 * No rows.
			 *
			 * @param array<string, mixed> $query The query.
			 * @param mixed                ...$scope Ignored.
			 *
			 * @return array<int, mixed>
			 */
			public function searchObjects(array $query, mixed ...$scope): array {
				return [];
			}
		};
		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')->willReturn('some-id');

		$result = $this->service->updateStatus(consultationId: 'con-uuid', newStatus: 'closed');

		$this->assertSame(expected: ['id' => 'con-uuid', 'status' => 'closed'], actual: $result);
		$this->assertCount(expectedCount: 1, haystack: $objectService->patches);
		$this->assertSame(expected: 'con-uuid', actual: $objectService->patches[0]->objectId);
		$this->assertSame(expected: 'closed', actual: $objectService->patches[0]->data['status']);
		$this->assertArrayHasKey(key: 'closedAt', array: $objectService->patches[0]->data);

	}//end testAStoredStatusOutsideTheGraphDoesNotConstrainTheMove()

	/**
	 * Only a request flagged mandatory with the boolean true, and not answered, closed or withdrawn, blocks.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testOnlyAnUnansweredMandatoryRequestBlocks(): void {
		$this->assertTrue(condition: $this->service->consultationBlocks(consultation: ['mandatory' => true, 'status' => 'received']));
		$this->assertTrue(condition: $this->service->consultationBlocks(consultation: ['mandatory' => true]));
		$this->assertFalse(condition: $this->service->consultationBlocks(consultation: ['mandatory' => true, 'status' => 'withdrawn']));
		$this->assertFalse(condition: $this->service->consultationBlocks(consultation: ['mandatory' => 'true', 'status' => 'open']));
		$this->assertFalse(condition: $this->service->consultationBlocks(consultation: ['status' => 'open']));

	}//end testOnlyAnUnansweredMandatoryRequestBlocks()

}//end class
