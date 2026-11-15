<?php

/**
 * MandaatGebruikService Unit Tests
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\MandaatGebruikService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * Every use of a mandate is logged as a row, the rows of a case or a mandate
 * are read back, a mandate's rows can be narrowed to a date range, and an
 * unconfigured or failing store never breaks the decision.
 *
 * @covers \OCA\Dossiq\Service\MandaatGebruikService
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class MandaatGebruikServiceTest extends TestCase {

	/**
	 * The mocked settings service.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Set up the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger   = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A fake object service.
	 *
	 * @param mixed $saved The saveObject result, or a Throwable it throws.
	 * @param mixed $rows  The search result, or a Throwable it throws.
	 *
	 * @return object The fake.
	 */
	private function objectService(mixed $saved = null, mixed $rows = []): object {
		return new class($saved, $rows) {
			/**
			 * Calls made.
			 *
			 * @var array<int,array<int,mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $saved The save result.
			 * @param mixed $rows  The search result.
			 */
			public function __construct(private mixed $saved, private mixed $rows) {
			}

			/**
			 * Save an object.
			 *
			 * @param array<string,mixed> $object   The object.
			 * @param string              $register The register.
			 * @param string              $schema   The schema.
			 * @param string|null         $uuid     The uuid.
			 *
			 * @return mixed
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): mixed {
				$register = $options->target->register;
				$schema = $options->target->schema;
				$uuid = $options->uuid;
				$this->calls[] = ['saveObject', $object, $register, $schema, $uuid];
				if ($this->saved instanceof \Throwable) {
					throw $this->saved;
				}

				return $this->saved;
			}

			/**
			 * Search by slugs.
			 *
			 * @param string              $register The register.
			 * @param string              $schema   The schema.
			 * @param array<string,mixed> $filters  The filters.
			 *
			 * @return mixed
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters): mixed {
				$this->calls[] = ['searchObjectsBySlug', $register, $schema, $filters];
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}

				return $this->rows;
			}
		};
	}//end objectService()

	/**
	 * The service under test.
	 *
	 * @param object|null $objectService The object service.
	 * @param string      $schema        The configured mandaat-gebruik schema.
	 *
	 * @return MandaatGebruikService The service.
	 */
	private function service(?object $objectService, string $schema = 'mandaat-gebruik'): MandaatGebruikService {
		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'dossiq'],
				['mandaat_gebruik_schema', '', $schema],
			]
		);

		return new MandaatGebruikService(settingsService: $this->settings, logger: $this->logger);
	}//end service()

	/**
	 * A use is saved as a row carrying the role snapshot and terms, and the saved row is returned.
	 *
	 * @return void
	 */
	public function testLogMandaatGebruikSavesTheRow(): void {
		$objects = $this->objectService(saved: ['id' => 'mg-1']);

		$result = $this->service(objectService: $objects)->logMandaatGebruik(
			caseId: 'case-1',
			decisionId: 'dec-1',
			mandateId: 'man-1',
			userId: 'alice',
			roleSnapshot: ['role' => 'behandelaar'],
			conditionsApplied: ['max' => 5000]
		);

		$this->assertSame(['id' => 'mg-1'], $result);
		$this->assertSame('saveObject', $objects->calls[0][0]);
		$this->assertSame(['dossiq', 'mandaat-gebruik', null], array_slice($objects->calls[0], 2));
		$row = $objects->calls[0][1];
		$this->assertSame(
			['caseId', 'decisionId', 'mandateId', 'userId', 'moment', 'roleOnMomentFromDecision', 'usedTerms', 'mandateVersionId'],
			array_keys($row)
		);
		$this->assertSame('case-1', $row['caseId']);
		$this->assertSame('dec-1', $row['decisionId']);
		$this->assertSame('alice', $row['userId']);
		$this->assertSame(['role' => 'behandelaar'], $row['roleOnMomentFromDecision']);
		$this->assertSame(['max' => 5000], $row['usedTerms']);
		$this->assertSame('man-1', $row['mandateVersionId']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $row['moment']);
	}//end testLogMandaatGebruikSavesTheRow()

	/**
	 * When the save returns nothing readable the row itself is returned.
	 *
	 * @return void
	 */
	public function testAnUnreadableSaveReturnsTheRow(): void {
		$result = $this->service(objectService: $this->objectService(saved: 'ok'))->logMandaatGebruik(
			caseId: 'case-1',
			decisionId: 'dec-1',
			mandateId: 'man-1',
			userId: 'alice'
		);

		$this->assertSame('man-1', $result['mandateId']);
		$this->assertSame([], $result['usedTerms']);
	}//end testAnUnreadableSaveReturnsTheRow()

	/**
	 * A failing save is logged as an error and the row is still returned.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLoggedAndReturnsTheRow(): void {
		$this->logger->expects($this->once())->method('error')->with(
			'MandaatGebruik log failed',
			['caseId' => 'case-1', 'error' => 'offline']
		);

		$result = $this->service(objectService: $this->objectService(saved: new RuntimeException('offline')))->logMandaatGebruik(
			caseId: 'case-1',
			decisionId: 'dec-1',
			mandateId: 'man-1',
			userId: 'alice'
		);

		$this->assertSame('case-1', $result['caseId']);
	}//end testAFailingSaveIsLoggedAndReturnsTheRow()

	/**
	 * Without a schema nothing is logged or read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredStoreReturnsEmpty(): void {
		$objects = $this->objectService(saved: ['id' => 'x'], rows: [['id' => 'x']]);
		$service = $this->service(objectService: $objects, schema: '');

		$this->assertSame([], $service->logMandaatGebruik(caseId: 'c', decisionId: 'd', mandateId: 'm', userId: 'u'));
		$this->assertSame([], $service->getDecisionAuditTrail(caseId: 'c'));
		$this->assertSame([], $service->getDecisionByMandaat(mandateId: 'm'));
		$this->assertSame([], $objects->calls);
	}//end testAnUnconfiguredStoreReturnsEmpty()

	/**
	 * Without OpenRegister nothing is logged or read.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterReturnsEmpty(): void {
		$service = $this->service(objectService: null);

		$this->assertSame([], $service->logMandaatGebruik(caseId: 'c', decisionId: 'd', mandateId: 'm', userId: 'u'));
		$this->assertSame([], $service->getDecisionAuditTrail(caseId: 'c'));
		$this->assertSame([], $service->getDecisionByMandaat(mandateId: 'm'));
	}//end testWithoutOpenRegisterReturnsEmpty()

	/**
	 * The audit trail of a case is the rows filtered by case id.
	 *
	 * @return void
	 */
	public function testGetDecisionAuditTrailFiltersByCase(): void {
		$objects = $this->objectService(rows: [['id' => 'mg-1'], ['id' => 'mg-2']]);

		$this->assertSame([['id' => 'mg-1'], ['id' => 'mg-2']], $this->service(objectService: $objects)->getDecisionAuditTrail(caseId: 'case-1'));
		$this->assertSame(['searchObjectsBySlug', 'dossiq', 'mandaat-gebruik', ['caseId' => 'case-1']], $objects->calls[0]);
	}//end testGetDecisionAuditTrailFiltersByCase()

	/**
	 * A failing search gives an empty trail and an empty mandate list.
	 *
	 * @return void
	 */
	public function testAFailingSearchReturnsEmpty(): void {
		$service = $this->service(objectService: $this->objectService(rows: new RuntimeException('down')));

		$this->assertSame([], $service->getDecisionAuditTrail(caseId: 'case-1'));
		$this->assertSame([], $service->getDecisionByMandaat(mandateId: 'man-1'));
	}//end testAFailingSearchReturnsEmpty()

	/**
	 * Without a range every row of the mandate is returned.
	 *
	 * @return void
	 */
	public function testGetDecisionByMandaatWithoutRangeReturnsAllRows(): void {
		$rows    = [['moment' => '2026-01-01T00:00:00+00:00'], ['moment' => '']];
		$objects = $this->objectService(rows: $rows);

		$this->assertSame($rows, $this->service(objectService: $objects)->getDecisionByMandaat(mandateId: 'man-1'));
		$this->assertSame(['mandateId' => 'man-1'], $objects->calls[0][3]);
	}//end testGetDecisionByMandaatWithoutRangeReturnsAllRows()

	/**
	 * The range is inclusive on whole days at both ends, and either end may be open.
	 *
	 * @return void
	 */
	public function testGetDecisionByMandaatFiltersByInclusiveDayRange(): void {
		$rows    = [
			['id' => 'before', 'moment' => '2026-02-28T23:59:59+00:00'],
			['id' => 'first', 'moment' => '2026-03-01T00:00:00+00:00'],
			['id' => 'last', 'moment' => '2026-03-31T23:59:59+00:00'],
			['id' => 'after', 'moment' => '2026-04-01T00:00:00+00:00'],
		];
		$service = $this->service(objectService: $this->objectService(rows: $rows));

		$both = $service->getDecisionByMandaat(
			mandateId: 'man-1',
			from: new DateTimeImmutable('2026-03-01 12:00'),
			until: new DateTimeImmutable('2026-03-31 08:00')
		);
		$this->assertSame(['first', 'last'], array_column($both, 'id'));

		$fromOnly = $service->getDecisionByMandaat(mandateId: 'man-1', from: new DateTimeImmutable('2026-03-31'));
		$this->assertSame(['last', 'after'], array_column($fromOnly, 'id'));

		$untilOnly = $service->getDecisionByMandaat(mandateId: 'man-1', until: new DateTimeImmutable('2026-03-01'));
		$this->assertSame(['before', 'first'], array_column($untilOnly, 'id'));
	}//end testGetDecisionByMandaatFiltersByInclusiveDayRange()
}//end class
