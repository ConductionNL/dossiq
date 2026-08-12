<?php

/**
 * ZaakdossierService Unit Tests
 *
 * Covers the ZGW DRC dossier orchestrator: upload (informatieobject + join +
 * SHA-256 integrity hash), link dedup, unlink (preserves document), status
 * lifecycle (forward-only, lock on definitief, reverse rejected), bulk status,
 * dossier grouping by type, metadata update immutability on definitief, and
 * default-classification resolution.
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
 * @spec openspec/changes/document-zaakdossier/tasks.md#T02
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\InformatieobjectAccessGuard;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Zaakdossier\InformatieobjectStatusLifecycle;
use OCA\Procest\Service\ZaakdossierService;
use OCA\Procest\Service\ZgwDocumentService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * ObjectService stub matching the named-arg signatures used by ZaakdossierService.
 */
interface DossierObjectServiceStub {

	/**
	 * Search by slug.
	 *
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param array $filters Filters.
	 *
	 * @return array
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters): array;

	/**
	 * Search by numeric @self.
	 *
	 * @param array $query Query payload.
	 *
	 * @return array
	 */
	public function searchObjects(array $query): array;

	/**
	 * Find one object.
	 *
	 * @param string $id Id.
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 *
	 * @return array
	 */
	public function find(string $id, string $register, string $schema): array;

	/**
	 * Save an object.
	 *
	 * @param array $object Payload.
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param string $uuid Optional uuid for update.
	 *
	 * @return object
	 */
	public function saveObject(array $object, string $register, string $schema, string $uuid = ''): object;

	/**
	 * Delete an object.
	 *
	 * @param string $uuid Uuid.
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 *
	 * @return void
	 */
	public function deleteObject(string $uuid, string $register, string $schema): void;
}//end interface

/**
 * Unit tests for ZaakdossierService.
 *
 * @covers \OCA\Procest\Service\ZaakdossierService
 *
 * @uses \OCA\Procest\Service\InformatieobjectAccessGuard
 * @uses \OCA\Procest\Service\Zaakdossier\InformatieobjectStatusLifecycle
 */
class ZaakdossierServiceTest extends TestCase {

	/**
	 * Mocked settings service.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * Mocked document service.
	 *
	 * @var ZgwDocumentService
	 */
	private ZgwDocumentService $documents;

	/**
	 * Real access guard (admin-clearance, permissive defaults).
	 *
	 * @var InformatieobjectAccessGuard
	 */
	private InformatieobjectAccessGuard $accessGuard;

	/**
	 * Service under test.
	 *
	 * @var ZaakdossierService
	 */
	private ZaakdossierService $service;

	/**
	 * Set up fixtures with all dossier config keys mapped.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->documents = $this->createMock(ZgwDocumentService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') {
				$map = [
					'register' => 'procest',
					'dossier_informatieobject_schema' => 'informatieobject',
					'dossier_zaakinformatieobject_schema' => 'zaakinformatieobject',
					'dossier_besluitinformatieobject_schema' => 'besluitinformatieobject',
					'dossier_informatieobjecttype_schema' => 'informatieobjecttype',
				];
				return ($map[$key] ?? $default);
			}
		);
		$this->accessGuard = new InformatieobjectAccessGuard(
			settingsService: $this->settings,
			groupManager: $this->createMock(\OCP\IGroupManager::class),
			logger: $this->createMock(LoggerInterface::class),
		);
		// A REAL lifecycle collaborator over the same mocked settings, so the
		// status-transition tests below still exercise the production state
		// machine end to end rather than a mock's canned answers.
		$this->service = new ZaakdossierService(
			settingsService: $this->settings,
			documentService: $this->documents,
			accessGuard: $this->accessGuard,
			statusLifecycle: new InformatieobjectStatusLifecycle(
				settingsService: $this->settings,
				logger: $this->createMock(LoggerInterface::class),
			),
			logger: $this->createMock(LoggerInterface::class),
		);

	}//end setUp()

	/**
	 * Build a saveObject return object exposing getUuid().
	 *
	 * @param string $uuid The uuid to return.
	 *
	 * @return object
	 */
	private function savedObject(string $uuid): object {
		return new class($uuid) {
			/**
			 * Constructor.
			 *
			 * @param string $uuid The uuid.
			 */
			public function __construct(
				private string $uuid,
			) {
			}

			/**
			 * Return the uuid.
			 *
			 * @return string
			 */
			public function getUuid(): string {
				return $this->uuid;
			}
		};
	}//end savedObject()

	/**
	 * uploadDocument throws when OpenRegister is unavailable.
	 *
	 * @return void
	 */
	public function testUploadFailsWithoutObjectService(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$this->service->uploadDocument('case-1', 'a.pdf', 'data', ['informatieobjecttype' => 'iot-1']);

	}//end testUploadFailsWithoutObjectService()

	/**
	 * uploadDocument requires informatieobjecttype.
	 *
	 * @return void
	 */
	public function testUploadRequiresType(): void {
		$os = $this->createMock(DossierObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($os);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('informatieobjecttype is required');

		$this->service->uploadDocument('case-1', 'a.pdf', 'data', []);

	}//end testUploadRequiresType()

	/**
	 * uploadDocument creates informatieobject + join, stores the binary, hashes content.
	 *
	 * @return void
	 */
	public function testUploadCreatesInformatieobjectAndJoinWithHash(): void {
		$os = $this->createMock(DossierObjectServiceStub::class);
		$content = 'PDF-CONTENT';
		$this->settings->method('getObjectService')->willReturn($os);

		$captured = [];
		$os->method('saveObject')->willReturnCallback(
			function (array $object, string $register, string $schema, string $uuid = '') use (&$captured) {
				$captured[$schema] = $object;
				return $this->savedObject($schema === 'informatieobject' ? 'inf-1' : 'zio-1');
			}
		);

		$this->documents->expects($this->once())
			->method('storeRaw')
			->with($this->equalTo('inf-1'), $this->equalTo('a.pdf'), $this->equalTo($content));

		$result = $this->service->uploadDocument(
			caseId: 'case-1',
			fileName: 'a.pdf',
			content: $content,
			metadata: ['informatieobjecttype' => 'iot-1', 'vertrouwelijkheidaanduiding' => 'intern', 'titel' => 'My doc'],
		);

		$this->assertSame('inf-1', $result['id']);
		$this->assertSame('concept', $result['status']);
		$this->assertSame('intern', $result['vertrouwelijkheidaanduiding']);
		$this->assertSame(hash('sha256', $content), $result['integriteit']['waarde']);
		$this->assertSame('sha256', $result['integriteit']['algoritme']);

		// Both schemas were written.
		$this->assertArrayHasKey('informatieobject', $captured);
		$this->assertArrayHasKey('zaakinformatieobject', $captured);
		$this->assertSame('case-1', $captured['zaakinformatieobject']['zaak']);
		$this->assertSame('inf-1', $captured['zaakinformatieobject']['informatieobject']);

	}//end testUploadCreatesInformatieobjectAndJoinWithHash()

	/**
	 * uploadDocument rejects a classification less restrictive than the type default.
	 *
	 * @return void
	 */
	public function testUploadRejectsLessRestrictiveClassification(): void {
		$os = $this->createMock(DossierObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($os);
		// Type default is 'geheim'; user requests 'openbaar' (less restrictive).
		$os->method('find')->willReturn(['id' => 'iot-1', 'vertrouwelijkheidaanduiding' => 'geheim']);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->uploadDocument(
			caseId: 'case-1',
			fileName: 'a.pdf',
			content: 'data',
			metadata: ['informatieobjecttype' => 'iot-1', 'vertrouwelijkheidaanduiding' => 'openbaar'],
		);

	}//end testUploadRejectsLessRestrictiveClassification()

	/**
	 * linkExistingInformatieobject deduplicates an existing join.
	 *
	 * @return void
	 */
	public function testLinkExistingDeduplicates(): void {
		$os = $this->createMock(DossierObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($os);

		$os->method('searchObjectsBySlug')->willReturn([['id' => 'zio-existing']]);
		$os->expects($this->never())->method('saveObject');

		$result = $this->service->linkExistingInformatieobject('case-2', 'inf-1');

		$this->assertSame('zio-existing', $result['id']);
		$this->assertFalse($result['duplicated']);

	}//end testLinkExistingDeduplicates()

	/**
	 * linkExistingInformatieobject creates a join when none exists.
	 *
	 * @return void
	 */
	public function testLinkExistingCreatesJoin(): void {
		$os = $this->createMock(DossierObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($os);

		$os->method('searchObjectsBySlug')->willReturn([]);
		$os->expects($this->once())->method('saveObject')->willReturn($this->savedObject('zio-new'));

		$result = $this->service->linkExistingInformatieobject('case-2', 'inf-1');

		$this->assertSame('zio-new', $result['id']);
		$this->assertSame('inf-1', $result['informatieobject']);

	}//end testLinkExistingCreatesJoin()

	/**
	 * unlinkInformatieobject deletes only the join records.
	 *
	 * @return void
	 */
	public function testUnlinkDeletesOnlyJoins(): void {
		$os = $this->createMock(DossierObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($os);

		$os->method('searchObjectsBySlug')->willReturn([['id' => 'zio-1'], ['id' => 'zio-2']]);
		$os->expects($this->exactly(2))->method('deleteObject');

		$this->assertTrue($this->service->unlinkInformatieobject('case-1', 'inf-1'));

	}//end testUnlinkDeletesOnlyJoins()

	/**
	 * isTransitionAllowed enforces a forward-only lifecycle.
	 *
	 * @return void
	 */
	public function testTransitionRules(): void {
		$this->assertTrue($this->service->isTransitionAllowed('concept', 'definitief'));
		$this->assertTrue($this->service->isTransitionAllowed('definitief', 'gearchiveerd'));
		$this->assertFalse($this->service->isTransitionAllowed('definitief', 'concept'));
		$this->assertFalse($this->service->isTransitionAllowed('gearchiveerd', 'definitief'));
		$this->assertFalse($this->service->isTransitionAllowed('concept', 'gearchiveerd'));
		$this->assertFalse($this->service->isTransitionAllowed('concept', 'concept'));

	}//end testTransitionRules()

	/**
	 * transitionStatus concept->definitief sets vergrendeldOp.
	 *
	 * @return void
	 */
	public function testTransitionToDefinitiefLocks(): void {
		$os = $this->createMock(DossierObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($os);
		$os->method('find')->willReturn(['id' => 'inf-1', 'status' => 'concept']);

		$captured = null;
		$os->method('saveObject')->willReturnCallback(
			function (array $object) use (&$captured) {
				$captured = $object;
				return $this->savedObject('inf-1');
			}
		);

		$result = $this->service->transitionStatus('inf-1', 'definitief');

		$this->assertSame('definitief', $result['status']);
		$this->assertArrayHasKey('vergrendeldOp', $result);
		$this->assertArrayHasKey('vergrendeldOp', (array)$captured);

	}//end testTransitionToDefinitiefLocks()

	/**
	 * transitionStatus rejects a reverse transition with InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testReverseTransitionRejected(): void {
		$os = $this->createMock(DossierObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($os);
		$os->method('find')->willReturn(['id' => 'inf-1', 'status' => 'definitief']);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->transitionStatus('inf-1', 'concept');

	}//end testReverseTransitionRejected()

	/**
	 * transitionStatus rejects an unknown status.
	 *
	 * @return void
	 */
	public function testInvalidStatusRejected(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid status: bogus');
		$this->service->transitionStatus('inf-1', 'bogus');

	}//end testInvalidStatusRejected()

	/**
	 * bulkTransitionStatus returns per-id success/failure.
	 *
	 * @return void
	 */
	public function testBulkTransitionReportsPerId(): void {
		$os = $this->createMock(DossierObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($os);
		$os->method('find')->willReturnCallback(
			static function (string $id) {
				// inf-bad is already definitief so concept->? path; we request definitief.
				if ($id === 'inf-bad') {
					return ['id' => $id, 'status' => 'definitief'];
				}
				return ['id' => $id, 'status' => 'concept'];
			}
		);
		$os->method('saveObject')->willReturn($this->savedObject('x'));

		$results = $this->service->bulkTransitionStatus(['inf-ok', 'inf-bad'], 'definitief');

		$this->assertTrue($results[0]['success']);
		$this->assertFalse($results[1]['success']);
		$this->assertArrayHasKey('error', $results[1]);

	}//end testBulkTransitionReportsPerId()

	/**
	 * groupByType groups documents and counts per type.
	 *
	 * @return void
	 */
	public function testGroupByType(): void {
		$docs = [
			['id' => '1', 'informatieobjecttype' => 'Advies'],
			['id' => '2', 'informatieobjecttype' => 'Advies'],
			['id' => '3', 'informatieobjecttype' => 'Aanvraag'],
		];

		$grouped = $this->service->groupByType($docs);

		$this->assertSame(3, $grouped['total']);
		$this->assertCount(2, $grouped['groups']);
		$byType = [];
		foreach ($grouped['groups'] as $group) {
			$byType[$group['informatieobjecttype']] = $group['count'];
		}

		$this->assertSame(2, $byType['Advies']);
		$this->assertSame(1, $byType['Aanvraag']);

	}//end testGroupByType()

	/**
	 * updateMetadata rejects mutation of a definitief document (HTTP 409 mapping).
	 *
	 * @return void
	 */
	public function testUpdateMetadataRejectsDefinitief(): void {
		$os = $this->createMock(DossierObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($os);
		$os->method('find')->willReturn(['id' => 'inf-1', 'status' => 'definitief']);

		$this->expectException(\DomainException::class);
		$this->service->updateMetadata('inf-1', ['titel' => 'New']);

	}//end testUpdateMetadataRejectsDefinitief()

	/**
	 * resolveDefaultClassification reads the type's default and falls back to intern.
	 *
	 * @return void
	 */
	public function testResolveDefaultClassification(): void {
		$os = $this->createMock(DossierObjectServiceStub::class);
		$this->settings->method('getObjectService')->willReturn($os);
		$os->method('find')->willReturn(['id' => 'iot-1', 'vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk']);

		$this->assertSame('zaakvertrouwelijk', $this->service->resolveDefaultClassification('iot-1'));

	}//end testResolveDefaultClassification()
}//end class
