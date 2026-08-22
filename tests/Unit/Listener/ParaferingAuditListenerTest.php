<?php

/**
 * ParaferingAuditListener Unit Tests
 *
 * Verifies that a ParafeerTransitionEvent is recorded through OpenRegister's
 * native audit trail (AuditTrailMapper::createAuditTrailEntry) with a namespaced
 * `procest.parafering.{action}` action string and the transition context in the
 * `$context` payload (persisted in OR's `changed` JSON column). Per
 * migrate-parafering-to-or-audit (ADR-022, consume-or-audit-trail-fleet-wide).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Event\ParafeerTransitionEvent;
use OCA\Dossiq\Listener\ParaferingAuditListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ParaferingAuditListener.
 *
 * @covers \OCA\Dossiq\Listener\ParaferingAuditListener
 *
 * @uses \OCA\Dossiq\Event\ParafeerTransitionEvent
 */
class ParaferingAuditListenerTest extends TestCase {
	/**
	 * Mocked OR audit-trail mapper.
	 *
	 * @var AuditTrailMapper|\PHPUnit\Framework\MockObject\MockObject
	 */
	private AuditTrailMapper $mapper;

	/**
	 * Mocked dossiq settings/OpenRegister bridge.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settings;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Listener under test.
	 *
	 * @var ParaferingAuditListener
	 */
	private ParaferingAuditListener $listener;

	/**
	 * Set up mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(AuditTrailMapper::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->listener = new ParaferingAuditListener(
			$this->mapper,
			$this->settings,
			$this->logger,
		);
	}//end setUp()

	/**
	 * Build an ObjectService test double exposing find().
	 *
	 * @param ObjectEntity|null $returns The entity find() should return
	 *
	 * @return object
	 */
	private function objectServiceReturning(?ObjectEntity $returns): object {
		return new class($returns) {
			/**
			 * @var ObjectEntity|null
			 */
			private $entity;

			// phpcs:ignore
			public function __construct($entity) {
				$this->entity = $entity;
			}

			// phpcs:ignore
			public function find($id, $register = null, $schema = null): ?ObjectEntity {
				return $this->entity;
			}
		};
	}//end objectServiceReturning()

	/**
	 * A non-parafering event is ignored (no audit write).
	 *
	 * @return void
	 */
	public function testNonParaferingEventIgnored(): void {
		$this->mapper->expects($this->never())->method('createAuditTrailEntry');
		$this->listener->handle(new Event());
	}//end testNonParaferingEventIgnored()

	/**
	 * An approved transition writes a namespaced OR audit entry with context.
	 *
	 * @return void
	 */
	public function testApprovedTransitionEmitsOrAuditEntry(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('route-001');

		$this->settings->method('getObjectService')->willReturn($this->objectServiceReturning($entity));
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'procest'],
				['voorstel_schema', '', 'proposal'],
			]
		);

		$captured = null;
		$this->mapper->expects($this->once())
			->method('createAuditTrailEntry')
			->with(
				$this->identicalTo($entity),
				$this->equalTo('procest.parafering.approved'),
				$this->callback(
					function ($context) use (&$captured) {
						$captured = $context;
						return is_array($context);
					}
				)
			);

		$event = new ParafeerTransitionEvent(
			proposalId: 'route-001',
			action: 'approved',
			step: 'step-2',
			actor: 'user-a',
			actorRole: 'parafeerder',
			reason: 'looks good',
		);

		$this->listener->handle($event);

		$this->assertSame('route-001', $captured['parafeerrouteId']);
		$this->assertSame('step-2', $captured['paraffeerstapId']);
		$this->assertSame('approved', $captured['toState']);
		$this->assertSame('user-a', $captured['actorUuid']);
		$this->assertSame('parafeerder', $captured['actorRole']);
		$this->assertSame('looks good', $captured['comment']);
	}//end testApprovedTransitionEmitsOrAuditEntry()

	/**
	 * A transition without a reason omits the comment key.
	 *
	 * @return void
	 */
	public function testTransitionWithoutReasonOmitsComment(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('route-002');

		$this->settings->method('getObjectService')->willReturn($this->objectServiceReturning($entity));
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'procest'],
				['voorstel_schema', '', 'proposal'],
			]
		);

		$captured = null;
		$this->mapper->expects($this->once())
			->method('createAuditTrailEntry')
			->with(
				$this->identicalTo($entity),
				$this->equalTo('procest.parafering.returned'),
				$this->callback(
					function ($context) use (&$captured) {
						$captured = $context;
						return true;
					}
				)
			);

		$event = new ParafeerTransitionEvent(
			proposalId: 'route-002',
			action: 'returned',
			step: null,
			actor: 'user-b',
			actorRole: 'author',
			reason: null,
		);

		$this->listener->handle($event);

		$this->assertArrayNotHasKey('comment', $captured);
		$this->assertNull($captured['paraffeerstapId']);
	}//end testTransitionWithoutReasonOmitsComment()

	/**
	 * When the voorstel ObjectEntity cannot be resolved, no audit entry is
	 * written and the failure is logged (warning) rather than thrown.
	 *
	 * @return void
	 */
	public function testUnresolvableVoorstelSkipsWriteAndDoesNotThrow(): void {
		$this->settings->method('getObjectService')->willReturn($this->objectServiceReturning(null));
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'procest'],
				['voorstel_schema', '', 'proposal'],
			]
		);

		$this->mapper->expects($this->never())->method('createAuditTrailEntry');
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$event = new ParafeerTransitionEvent(
			proposalId: 'missing',
			action: 'approved',
			step: null,
			actor: 'user-c',
			actorRole: 'parafeerder',
		);

		$this->listener->handle($event);
		$this->addToAssertionCount(1);
	}//end testUnresolvableVoorstelSkipsWriteAndDoesNotThrow()

	/**
	 * A non-table transition name is namespaced verbatim (no fallback).
	 *
	 * @return void
	 */
	public function testRawTransitionNameNamespacedVerbatim(): void {
		$entity = new ObjectEntity();
		$entity->setUuid('route-003');

		$this->settings->method('getObjectService')->willReturn($this->objectServiceReturning($entity));
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'procest'],
				['voorstel_schema', '', 'proposal'],
			]
		);

		$this->mapper->expects($this->once())
			->method('createAuditTrailEntry')
			->with(
				$this->identicalTo($entity),
				$this->equalTo('procest.parafering.route-changed'),
				$this->isType('array')
			);

		$event = new ParafeerTransitionEvent(
			proposalId: 'route-003',
			action: 'route-changed',
			step: null,
			actor: 'user-d',
			actorRole: 'beheerder',
			reason: 'reroute',
		);

		$this->listener->handle($event);
	}//end testRawTransitionNameNamespacedVerbatim()
}//end class
