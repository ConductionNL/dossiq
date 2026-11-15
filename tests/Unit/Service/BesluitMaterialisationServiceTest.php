<?php

/**
 * BesluitMaterialisationService Unit Tests
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

use OCA\Dossiq\Service\BesluitMaterialisationService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * A concluded decision becomes a Besluit object in the configured schema,
 * created or updated by uuid, and a failed write is logged and rethrown.
 *
 * @covers \OCA\Dossiq\Service\BesluitMaterialisationService
 */
class BesluitMaterialisationServiceTest extends TestCase {

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
	 * A fake object service whose saveObject returns or throws the given value.
	 *
	 * @param mixed $result What saveObject returns, or a Throwable it throws.
	 *
	 * @return object The fake.
	 */
	private function objectService(mixed $result): object {
		return new class($result) {
			/**
			 * The saveObject calls.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $saves = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $result The result.
			 */
			public function __construct(private mixed $result) {
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
				$this->saves[] = ['object' => $object, 'register' => $register, 'schema' => $schema, 'uuid' => $uuid];
				if ($this->result instanceof \Throwable) {
					throw $this->result;
				}

				return $this->result;
			}
		};
	}//end objectService()

	/**
	 * The service under test.
	 *
	 * @param object|null $objectService The object service.
	 * @param string      $schema        The configured besluit schema.
	 *
	 * @return BesluitMaterialisationService The service.
	 */
	private function service(?object $objectService, string $schema = 'besluit'): BesluitMaterialisationService {
		$this->settings->method('getObjectService')->willReturn($objectService);
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'dossiq'],
				['besluit_schema', '', $schema],
			]
		);

		return new BesluitMaterialisationService(settingsService: $this->settings, logger: $this->logger);
	}//end service()

	/**
	 * The payload maps the outcome onto the Besluit fields and names decidesk as source.
	 *
	 * @return void
	 */
	public function testBuildBesluitPayloadMapsTheOutcome(): void {
		$payload = $this->service(objectService: null)->buildBesluitPayload(
			caseId: 'case-1',
			outcome: ['result' => 'granted', 'decidedAt' => '2026-11-01T10:00:00+00:00', 'rationale' => 'Complete', 'signer' => 'alice', 'method' => 'mandate']
		);

		$this->assertSame(
			[
				'zaakRef'        => 'case-1',
				'result'         => 'granted',
				'date'           => '2026-11-01T10:00:00+00:00',
				'notes'          => 'Complete',
				'mandaathouder'  => 'alice',
				'besluitMethode' => 'mandate',
				'besluitBron'    => 'decidesk',
			],
			$payload
		);
	}//end testBuildBesluitPayloadMapsTheOutcome()

	/**
	 * Missing outcome fields become empty strings and the date defaults to now.
	 *
	 * @return void
	 */
	public function testBuildBesluitPayloadDefaultsMissingFields(): void {
		$payload = $this->service(objectService: null)->buildBesluitPayload(caseId: 'case-1', outcome: []);

		$this->assertSame('', $payload['result']);
		$this->assertSame('', $payload['notes']);
		$this->assertSame('', $payload['mandaathouder']);
		$this->assertSame('', $payload['besluitMethode']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $payload['date']);
	}//end testBuildBesluitPayloadDefaultsMissingFields()

	/**
	 * Without a decision id a new Besluit is created in the configured schema.
	 *
	 * @return void
	 */
	public function testMaterialiseCreatesANewBesluit(): void {
		$objects = $this->objectService(result: ['id' => 'b-1', 'result' => 'granted']);

		$record = $this->service(objectService: $objects)->materialise(
			caseId: 'case-1',
			decisionId: '',
			outcome: ['result' => 'granted', 'decidedAt' => '2026-11-01']
		);

		$this->assertSame(['id' => 'b-1', 'result' => 'granted'], $record);
		$this->assertCount(1, $objects->saves);
		$this->assertSame('dossiq', $objects->saves[0]['register']);
		$this->assertSame('besluit', $objects->saves[0]['schema']);
		$this->assertNull($objects->saves[0]['uuid']);
		$this->assertArrayNotHasKey('uuid', $objects->saves[0]['object']);
		$this->assertSame('case-1', $objects->saves[0]['object']['zaakRef']);
	}//end testMaterialiseCreatesANewBesluit()

	/**
	 * A decision id updates that Besluit, and an unset schema falls back to `decision`.
	 *
	 * @return void
	 */
	public function testMaterialiseUpdatesByUuidInTheDefaultSchema(): void {
		$objects = $this->objectService(result: ['id' => 'b-7']);

		$this->service(objectService: $objects, schema: '')->materialise(
			caseId: 'case-1',
			decisionId: 'b-7',
			outcome: ['result' => 'refused']
		);

		$this->assertSame('decision', $objects->saves[0]['schema']);
		$this->assertSame('b-7', $objects->saves[0]['uuid']);
		$this->assertSame('b-7', $objects->saves[0]['object']['uuid']);
	}//end testMaterialiseUpdatesByUuidInTheDefaultSchema()

	/**
	 * A non-array save result is replaced by a minimal record of case and result.
	 *
	 * @return void
	 */
	public function testANonArraySaveResultGivesAMinimalRecord(): void {
		$record = $this->service(objectService: $this->objectService(result: new \stdClass()))->materialise(
			caseId: 'case-1',
			decisionId: '',
			outcome: ['result' => 'granted']
		);

		$this->assertSame(['caseId' => 'case-1', 'result' => 'granted'], $record);
	}//end testANonArraySaveResultGivesAMinimalRecord()

	/**
	 * A failed write is logged and rethrown with the cause chained.
	 *
	 * @return void
	 */
	public function testAFailedWriteIsLoggedAndRethrown(): void {
		$cause = new RuntimeException('disk full');
		$this->logger->expects($this->once())->method('error')->with(
			'BesluitMaterialisationService: persist failed',
			['caseId' => 'case-1', 'error' => 'disk full']
		);

		try {
			$this->service(objectService: $this->objectService(result: $cause))->materialise(
				caseId: 'case-1',
				decisionId: '',
				outcome: ['result' => 'granted']
			);
			$this->fail('Expected the write failure to be rethrown');
		} catch (RuntimeException $e) {
			$this->assertSame('Besluit persist failed: disk full', $e->getMessage());
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testAFailedWriteIsLoggedAndRethrown()

	/**
	 * Without OpenRegister materialising is refused.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterMaterialisingThrows(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available; cannot materialise Besluit');

		$this->service(objectService: null)->materialise(caseId: 'case-1', decisionId: '', outcome: ['result' => 'granted']);
	}//end testWithoutOpenRegisterMaterialisingThrows()

	/**
	 * A concluded event's outcome, or else its status, becomes the result, and the event is kept raw.
	 *
	 * @return void
	 */
	public function testMaterialiseFromConcludedEventFallsBackToTheStatus(): void {
		$objects = $this->objectService(result: null);
		$event   = ['status' => 'withdrawn', 'decidedAt' => '2026-11-02', 'signer' => 'bob'];

		$record = $this->service(objectService: $objects)->materialiseFromConcludedEvent(caseId: 'case-2', decisionId: '', event: $event);

		$this->assertSame(['caseId' => 'case-2', 'result' => 'withdrawn'], $record);
		$this->assertSame('withdrawn', $objects->saves[0]['object']['result']);
		$this->assertSame('2026-11-02', $objects->saves[0]['object']['date']);
		$this->assertSame('bob', $objects->saves[0]['object']['mandaathouder']);
		$this->assertSame('', $objects->saves[0]['object']['notes']);
	}//end testMaterialiseFromConcludedEventFallsBackToTheStatus()

	/**
	 * An event outcome wins over its status.
	 *
	 * @return void
	 */
	public function testAnEventOutcomeWinsOverItsStatus(): void {
		$objects = $this->objectService(result: null);

		$record = $this->service(objectService: $objects)->materialiseFromConcludedEvent(
			caseId: 'case-2',
			decisionId: 'b-2',
			event: ['outcome' => 'granted', 'status' => 'concluded']
		);

		$this->assertSame(['caseId' => 'case-2', 'result' => 'granted'], $record);
		$this->assertSame('b-2', $objects->saves[0]['uuid']);
	}//end testAnEventOutcomeWinsOverItsStatus()
}//end class
