<?php

/**
 * BezwaarDecisionListener Unit Tests.
 *
 * These tests exist because the guard was dead and nothing said so. The
 * listener called OpenRegister's `ObjectService::findAll()` with three
 * positional arguments while that method takes ONE config array, so every
 * invocation raised a TypeError that the listener's own `catch (Throwable)`
 * converted into "a published decision exists". The guard therefore never
 * blocked a single transition, and the suite was green throughout.
 *
 * So the assertions below deliberately look at the ARGUMENT the listener
 * hands to OpenRegister — not merely at whether some call happened. A test
 * that only asserted "findAll was called" would have passed against the
 * broken code too.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Listener
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
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\Procest\Listener\BezwaarDecisionListener;
use OCA\Procest\Service\SettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BezwaarDecisionListener.
 *
 * @covers \OCA\Procest\Listener\BezwaarDecisionListener
 */
class BezwaarDecisionListenerTest extends TestCase {

	/**
	 * The protected status the guard defends.
	 *
	 * @var string
	 */
	private const PROTECTED_STATUS = 'Beslissing op bezwaar';

	/**
	 * Mocked settings/OpenRegister bridge.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Captured findAll() argument, or null when findAll was never reached.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $capturedFindAllConfig = null;

	/**
	 * Captured saveObject() keyword arguments, or null when no revert happened.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $capturedSave = null;

	/**
	 * Wire fresh mocks before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return match ($key) {
					'register' => 'procest-register',
					'bezwaar_schema' => 'bezwaar',
					'bezwaar_decision_schema' => 'bezwaardecision',
					default => $default,
				};
			}
		);
	}//end setUp()

	/**
	 * Build a doubled OpenRegister ObjectService.
	 *
	 * `findAll()` is declared with ONE array parameter, exactly as
	 * OpenRegister declares it, so a positional three-argument call from the
	 * listener is a TypeError here just as it is in production.
	 *
	 * @param array<int, array<string, mixed>> $decisions Rows findAll should return.
	 *
	 * @return object The doubled service.
	 */
	private function objectService(array $decisions): object {
		$test = $this;

		return new class($test, $decisions) {
			/**
			 * Constructor.
			 *
			 * @param BezwaarDecisionListenerTest $test The owning test case.
			 * @param array<int, array<string, mixed>> $decisions Rows to return.
			 */
			public function __construct(
				private BezwaarDecisionListenerTest $test,
				private array $decisions,
			) {
			}//end __construct()

			/**
			 * Mirror of OpenRegister's single-config-array signature.
			 *
			 * @param array<string, mixed> $config The find configuration.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config = []): array {
				$this->test->recordFindAll(config: $config);
				return $this->decisions;
			}//end findAll()

			/**
			 * Capture a revert write.
			 *
			 * @param array<string, mixed> $object The patch payload.
			 * @param string $register The register slug.
			 * @param string $schema The schema slug.
			 * @param string $uuid The object uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, string $uuid): array {
				$this->test->recordSave(
					save: [
						'object' => $object,
						'register' => $register,
						'schema' => $schema,
						'uuid' => $uuid,
					]
				);
				return $object;
			}//end saveObject()
		};
	}//end objectService()

	/**
	 * Record the config findAll() was called with.
	 *
	 * @param array<string, mixed> $config The captured configuration.
	 *
	 * @return void
	 */
	public function recordFindAll(array $config): void {
		$this->capturedFindAllConfig = $config;
	}//end recordFindAll()

	/**
	 * Record a captured revert write.
	 *
	 * @param array<string, mixed> $save The captured write.
	 *
	 * @return void
	 */
	public function recordSave(array $save): void {
		$this->capturedSave = $save;
	}//end recordSave()

	/**
	 * Build an ObjectUpdatedEvent carrying the given payloads.
	 *
	 * @param array<string, mixed> $new The post-update payload.
	 * @param array<string, mixed> $old The pre-update payload.
	 *
	 * @return ObjectUpdatedEvent
	 */
	private function event(array $new, array $old): ObjectUpdatedEvent {
		$newEntity = $this->createMock(ObjectEntity::class);
		$newEntity->method('jsonSerialize')->willReturn($new);
		$oldEntity = $this->createMock(ObjectEntity::class);
		$oldEntity->method('jsonSerialize')->willReturn($old);

		return new ObjectUpdatedEvent($newEntity, $oldEntity);
	}//end event()

	/**
	 * A bezwaar payload sitting on the protected status.
	 *
	 * @return array<string, mixed>
	 */
	private function bezwaarOnProtectedStatus(): array {
		return [
			'id' => 'bezwaar-1',
			'@self' => ['schema' => 'bezwaar'],
			'status' => self::PROTECTED_STATUS,
		];
	}//end bezwaarOnProtectedStatus()

	/**
	 * Build the listener under test.
	 *
	 * @param object $objectService The doubled OpenRegister service.
	 *
	 * @return BezwaarDecisionListener
	 */
	private function listener(object $objectService): BezwaarDecisionListener {
		$this->settings->method('getObjectService')->willReturn($objectService);
		return new BezwaarDecisionListener($this->settings, $this->logger);
	}//end listener()

	/**
	 * The decision probe must reach OpenRegister in the shape OpenRegister
	 * declares: one config array, with register/schema/bezwaar as filters.
	 *
	 * This is the assertion the old code could not have passed. It asserts on
	 * the argument itself, not on the fact that a call occurred.
	 *
	 * @return void
	 */
	public function testProbeCallsFindAllWithASingleConfigArray(): void {
		$listener = $this->listener($this->objectService([['status' => 'published']]));

		$listener->handle(
			$this->event(
				$this->bezwaarOnProtectedStatus(),
				['status' => 'In behandeling']
			)
		);

		$this->assertNotNull(
			$this->capturedFindAllConfig,
			'the guard never reached findAll()'
		);
		$this->assertSame(
			[
				'register' => 'procest-register',
				'schema' => 'bezwaardecision',
				'bezwaar' => 'bezwaar-1',
			],
			$this->capturedFindAllConfig['filters'],
			'the probe must filter by register, schema and bezwaar id'
		);
	}//end testProbeCallsFindAllWithASingleConfigArray()

	/**
	 * The probe must be bounded — it runs on the write path of every bezwaar
	 * update, so it must not list the whole decision set.
	 *
	 * @return void
	 */
	public function testProbeIsBounded(): void {
		$listener = $this->listener($this->objectService([['status' => 'published']]));

		$listener->handle(
			$this->event(
				$this->bezwaarOnProtectedStatus(),
				['status' => 'In behandeling']
			)
		);

		$this->assertArrayHasKey(
			'limit',
			(array)$this->capturedFindAllConfig,
			'an unbounded probe on the write path lists every decision on every save'
		);
		$this->assertGreaterThan(0, $this->capturedFindAllConfig['limit']);
	}//end testProbeIsBounded()

	/**
	 * With no decided decision, the guard must revert the status to the
	 * previous value carried by the event.
	 *
	 * @return void
	 */
	public function testRevertsWhenNoDecidedDecisionExists(): void {
		$listener = $this->listener($this->objectService([['status' => 'concept']]));

		$listener->handle(
			$this->event(
				$this->bezwaarOnProtectedStatus(),
				['status' => 'In behandeling']
			)
		);

		$this->assertNotNull($this->capturedSave, 'the guard did not revert');
		$this->assertSame(['status' => 'In behandeling'], $this->capturedSave['object']);
		$this->assertSame('bezwaar-1', $this->capturedSave['uuid']);
		$this->assertSame('bezwaar', $this->capturedSave['schema']);
	}//end testRevertsWhenNoDecidedDecisionExists()

	/**
	 * A decision delegated to decidesk carries a decisionRef rather than the
	 * legacy local `status: published`, and must also satisfy the guard.
	 *
	 * @return void
	 */
	public function testDecisionRefSatisfiesTheGuard(): void {
		$listener = $this->listener($this->objectService([['decisionRef' => 'besluit-9']]));

		$listener->handle(
			$this->event(
				$this->bezwaarOnProtectedStatus(),
				['status' => 'In behandeling']
			)
		);

		$this->assertNull($this->capturedSave, 'a decidesk-delegated besluit must not be reverted');
	}//end testDecisionRefSatisfiesTheGuard()

	/**
	 * A FULL page means the bound, not the data, ended the scan. Reverting on
	 * an incomplete scan would block a legitimate transition, so the guard
	 * fails open.
	 *
	 * @return void
	 */
	public function testFailsOpenWhenTheProbeHitsItsBound(): void {
		$undecided = array_fill(0, 100, ['status' => 'concept']);
		$listener = $this->listener($this->objectService($undecided));

		$listener->handle(
			$this->event(
				$this->bezwaarOnProtectedStatus(),
				['status' => 'In behandeling']
			)
		);

		$this->assertNull(
			$this->capturedSave,
			'a scan that stopped at its bound must not be treated as proof of absence'
		);
	}//end testFailsOpenWhenTheProbeHitsItsBound()

	/**
	 * A transition into any other status is none of this guard's business.
	 *
	 * @return void
	 */
	public function testIgnoresTransitionsIntoOtherStatuses(): void {
		$listener = $this->listener($this->objectService([]));

		$listener->handle(
			$this->event(
				[
					'id' => 'bezwaar-1',
					'@self' => ['schema' => 'bezwaar'],
					'status' => 'In behandeling',
				],
				['status' => 'Ontvangen']
			)
		);

		$this->assertNull($this->capturedFindAllConfig, 'the guard probed on an unrelated status');
		$this->assertNull($this->capturedSave);
	}//end testIgnoresTransitionsIntoOtherStatuses()
}//end class
