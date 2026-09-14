<?php

/**
 * IntegrationStatusService unit tests.
 *
 * The service tells integriq's connection registry what dossiq observed. Every
 * test here guards one way it could quietly stop doing that: sending the wrong
 * app or key, sending a status integriq would drop, turning a working
 * connection test into a 500 because a listener threw, logging a fault when
 * integriq is simply not installed, or asking about a connection the save
 * never touched.
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
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\IntegrationStatusService;
use OCA\Integriq\Event\ConnectionRefreshRequestedEvent;
use OCA\Integriq\Event\ConnectionStatusReportedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * Unit tests for IntegrationStatusService.
 *
 * The integriq event classes come from tests/Stubs/Integriq/Event, which mirror
 * design D6 of the hydra change connection-registry.
 *
 * @covers \OCA\Dossiq\Service\IntegrationStatusService
 */
class IntegrationStatusServiceTest extends TestCase {

	/**
	 * Mocked event dispatcher.
	 *
	 * @var IEventDispatcher&\PHPUnit\Framework\MockObject\MockObject
	 */
	private IEventDispatcher $dispatcher;

	/**
	 * Mocked logger.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Every event handed to the dispatcher.
	 *
	 * @var array<int, Event>
	 */
	private array $sent = [];

	/**
	 * Set up the fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->sent = [];
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function (Event $event): void {
				$this->sent[] = $event;
			}
		);
	}//end setUp()

	/**
	 * The service as production builds it.
	 *
	 * @return IntegrationStatusService
	 */
	private function service(): IntegrationStatusService {
		return new IntegrationStatusService(
			eventDispatcher: $this->dispatcher,
			logger: $this->logger,
		);
	}//end service()

	/**
	 * The service as it behaves on an instance without integriq.
	 *
	 * Only the class lookup is replaced. The stubs make both event classes
	 * resolvable in this process, so absence has to be simulated at the one
	 * seam that asks.
	 *
	 * @return IntegrationStatusService
	 */
	private function serviceWithoutIntegriq(): IntegrationStatusService {
		return new class($this->dispatcher, $this->logger) extends IntegrationStatusService {

			/**
			 * Integriq is not installed, so no class resolves.
			 *
			 * @param string $eventClass The class name asked for.
			 *
			 * @return string|null Always null.
			 */
			protected function resolveEventClass(string $eventClass): ?string {
				return null;
			}//end resolveEventClass()
		};
	}//end serviceWithoutIntegriq()

	/**
	 * A report reaches integriq as one event with dossiq's id and the probe's words.
	 *
	 * @return void
	 */
	public function testAReportIsSentWithTheAppKeyStatusAndMessage(): void {
		$this->assertTrue($this->service()->record(key: 'mailbox', status: 'error', message: 'Connection refused'));

		$this->assertCount(1, $this->sent);
		$event = $this->sent[0];
		$this->assertInstanceOf(ConnectionStatusReportedEvent::class, $event);
		$this->assertSame('dossiq', $event->app);
		$this->assertSame('mailbox', $event->key);
		$this->assertSame('error', $event->status);
		$this->assertSame('Connection refused', $event->message);
	}//end testAReportIsSentWithTheAppKeyStatusAndMessage()

	/**
	 * The event names are the ones the contract fixes, and they resolve here.
	 *
	 * A string class name is exactly the reference that rots into a silent
	 * no-op after a rename, so it is compared to the stub's real name.
	 *
	 * @return void
	 */
	public function testTheEventNamesAreTheContractNames(): void {
		$this->assertSame(ConnectionStatusReportedEvent::class, IntegrationStatusService::STATUS_EVENT);
		$this->assertSame(ConnectionRefreshRequestedEvent::class, IntegrationStatusService::REFRESH_EVENT);
	}//end testTheEventNamesAreTheContractNames()

	/**
	 * The class lookup answers null for a class nobody ships.
	 *
	 * This is the real guard, not the test double: an instance without
	 * integriq has no class, and the lookup must say so instead of throwing.
	 *
	 * @return void
	 */
	public function testTheLookupAnswersNullForAnAbsentClass(): void {
		$method = new ReflectionMethod(IntegrationStatusService::class, 'resolveEventClass');

		$this->assertNull($method->invoke($this->service(), 'OCA\\Nobody\\Event\\ShipsThisEvent'));
		$this->assertSame(
			'\\' . IntegrationStatusService::STATUS_EVENT,
			$method->invoke($this->service(), IntegrationStatusService::STATUS_EVENT)
		);
	}//end testTheLookupAnswersNullForAnAbsentClass()

	/**
	 * Without integriq nothing is sent, nothing is logged and nothing throws.
	 *
	 * A missing optional app is not a fault. A warning on every IMAP test
	 * would fill the log of every instance that never wanted integriq.
	 *
	 * @return void
	 */
	public function testWithoutIntegriqNothingIsSentOrLogged(): void {
		$this->dispatcher->expects($this->never())->method('dispatchTyped');
		$this->logger->expects($this->never())->method('warning');
		$this->logger->expects($this->never())->method('error');

		$service = $this->serviceWithoutIntegriq();

		$this->assertFalse($service->record(key: 'mailbox', status: 'configured', message: 'ok'));
		$this->assertSame([], $service->recordFromSave(saved: ['identification_method' => 'both']));
	}//end testWithoutIntegriqNothingIsSentOrLogged()

	/**
	 * An unknown key is refused with a warning and never sent.
	 *
	 * @return void
	 */
	public function testAnUnknownKeyIsRefused(): void {
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('unknown connection key'), ['key' => 'sharepoint']);

		$this->assertFalse($this->service()->record(key: 'sharepoint', status: 'configured'));
		$this->assertSame([], $this->sent);
	}//end testAnUnknownKeyIsRefused()

	/**
	 * A status outside the five is refused, and each of the five is sent.
	 *
	 * @return void
	 */
	public function testStatusMustBeOneOfTheFive(): void {
		$service = $this->service();

		$this->assertFalse($service->record(key: 'mailbox', status: 'degraded'));
		$this->assertSame([], $this->sent);

		foreach (IntegrationStatusService::STATUSES as $status) {
			$this->assertTrue($service->record(key: 'mailbox', status: $status));
		}

		$this->assertCount(count(IntegrationStatusService::STATUSES), $this->sent);
	}//end testStatusMustBeOneOfTheFive()

	/**
	 * A listener that throws never escapes into the probe that reported.
	 *
	 * @return void
	 */
	public function testAThrowingListenerNeverEscapes(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willThrowException(new RuntimeException('registry down'));
		$this->logger->expects($this->atLeastOnce())->method('warning')
			->with($this->stringContains('could not send'), $this->anything());

		$service = new IntegrationStatusService(eventDispatcher: $dispatcher, logger: $this->logger);

		$this->assertFalse($service->record(key: 'stuf', status: 'configured', message: 'Gemeente Zuid'));
		$this->assertSame([], $service->recordFromSave(saved: ['identification_method' => 'both']));
	}//end testAThrowingListenerNeverEscapes()

	/**
	 * A save asks for a refresh of the connection whose keys it carried.
	 *
	 * Dossiq sends no status for it: integriq reads the saved values and
	 * decides (design D6).
	 *
	 * @return void
	 */
	public function testASaveRequestsARefreshForTheTouchedConnection(): void {
		$refreshed = $this->service()->recordFromSave(saved: ['identification_method' => 'both']);

		$this->assertSame(['kcc'], $refreshed);
		$this->assertCount(1, $this->sent);
		$event = $this->sent[0];
		$this->assertInstanceOf(ConnectionRefreshRequestedEvent::class, $event);
		$this->assertSame('dossiq', $event->app);
		$this->assertSame('kcc', $event->key);
	}//end testASaveRequestsARefreshForTheTouchedConnection()

	/**
	 * A cleared key is still a touched key, so the refresh still goes out.
	 *
	 * Clearing the adapter class is what flips Berichtenbox back to Simulated,
	 * and integriq only learns that if it is asked.
	 *
	 * @return void
	 */
	public function testAClearedKeyStillRequestsARefresh(): void {
		$this->assertSame(['berichtenbox'], $this->service()->recordFromSave(saved: ['berichtenbox_adapter' => '']));
	}//end testAClearedKeyStillRequestsARefresh()

	/**
	 * One save touching two sections asks about both, in declaration order.
	 *
	 * @return void
	 */
	public function testASaveTouchingTwoSectionsRefreshesBoth(): void {
		$refreshed = $this->service()->recordFromSave(
			saved: ['case_schema' => 'case', 'dwangsom_callback_secret' => 's3cret']
		);

		$this->assertSame(['zgw', 'financial'], $refreshed);
		$this->assertCount(2, $this->sent);
	}//end testASaveTouchingTwoSectionsRefreshesBoth()

	/**
	 * A save that named no connection's keys sends nothing.
	 *
	 * @return void
	 */
	public function testASaveOnlyTouchesTheSectionsItNamed(): void {
		$this->assertSame([], $this->service()->recordFromSave(saved: ['advice_reminder_days' => '7']));
		$this->assertSame([], $this->sent);
	}//end testASaveOnlyTouchesTheSectionsItNamed()

	/**
	 * Every key the save map names is a declared connection.
	 *
	 * @return void
	 */
	public function testEverySaveMappedKeyIsADeclaredConnection(): void {
		foreach (array_keys(IntegrationStatusService::SAVE_REQUIRED_KEYS) as $key) {
			$this->assertContains($key, IntegrationStatusService::KEYS);
		}
	}//end testEverySaveMappedKeyIsADeclaredConnection()

	/**
	 * The probed connections are deliberately absent from the save map.
	 *
	 * A saved form must not ask integriq to overrule what the network answered.
	 *
	 * @return void
	 */
	public function testProbedConnectionsAreNotDrivenBySaves(): void {
		$this->assertArrayNotHasKey('stuf', IntegrationStatusService::SAVE_REQUIRED_KEYS);
		$this->assertArrayNotHasKey('mailbox', IntegrationStatusService::SAVE_REQUIRED_KEYS);
		$this->assertArrayNotHasKey('store', IntegrationStatusService::SAVE_REQUIRED_KEYS);
	}//end testProbedConnectionsAreNotDrivenBySaves()
}//end class
