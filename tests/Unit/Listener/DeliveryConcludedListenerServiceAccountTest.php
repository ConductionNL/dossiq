<?php

/**
 * DeliveryConcludedListener writes as the background service account.
 *
 * Integriq raises DeliveryConcludedEvent from its own background work, with
 * nobody signed in, so OpenRegister refused the write that projects the
 * delivery outcome onto the case's publication record. This drives the REAL
 * listener into a register that refuses a write from nobody. Doubled:
 * SettingsService (hands over the register and the schema slugs).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\DeliveryConcludedListener;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCA\Integriq\Event\DeliveryConcludedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The delivery outcome lands as the configured account, or not at all.
 *
 * @covers \OCA\Dossiq\Listener\DeliveryConcludedListener
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 */
class DeliveryConcludedListenerServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The register the listener writes to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * One case with one publication whose delivery was requested.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->register->seed(
			schema: 'case',
			id: 'c1',
			row: [
				'title' => 'Besluit',
				'publications' => [
					[
						'channel' => 'gemeenteblad',
						'delivery' => ['status' => 'requested', 'correlationId' => 'corr-1'],
					],
				],
			]
		);
	}//end setUp()

	/**
	 * Nobody signed in: the outcome is written as the service account.
	 *
	 * @return void
	 */
	public function testTheEventWritesAsTheServiceAccount(): void {
		$this->listener()->handle($this->event());

		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: ['case']);
		$delivery = $this->register->row(schema: 'case', id: 'c1')['publications'][0]['delivery'];
		$this->assertSame('delivered', $delivery['status']);
		$this->assertSame(2, $delivery['attempts']);
	}//end testTheEventWritesAsTheServiceAccount()

	/**
	 * A signed-in caller keeps writing as themselves.
	 *
	 * @return void
	 */
	public function testASignedInUserStaysTheWriter(): void {
		$this->acting = $this->backgroundUser(uid: 'behandelaar-1');

		$this->listener()->handle($this->event());

		$this->assertSame([], $this->register->refusals);
		$this->assertSame(['behandelaar-1'], $this->register->writers());
		$this->assertSame('behandelaar-1', $this->actingUid());
	}//end testASignedInUserStaysTheWriter()

	/**
	 * Without an account nothing is written or even attempted.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheEventWritesNothing(): void {
		$this->configuredAccount = '';

		$this->listener()->handle($this->event());

		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertSame('requested', $this->register->row(schema: 'case', id: 'c1')['publications'][0]['delivery']['status']);
	}//end testWithoutAnAccountTheEventWritesNothing()

	/**
	 * The concluded delivery, as integriq raises it.
	 *
	 * @return DeliveryConcludedEvent The event.
	 */
	private function event(): DeliveryConcludedEvent {
		return new DeliveryConcludedEvent(
			sourceApp: 'dossiq',
			correlationId: 'corr-1',
			subjectId: 'c1',
			channel: 'gemeenteblad',
			status: 'delivered',
			eventId: 'evt-1',
			messageId: 'msg-1',
			attempts: 2,
			error: null,
			concludedAt: '2026-10-07T09:00:00+00:00',
		);
	}//end event()

	/**
	 * The real listener over the register.
	 *
	 * @return DeliveryConcludedListener The listener.
	 */
	private function listener(): DeliveryConcludedListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => $default,
			}
		);

		return $this->buildWith(
			DeliveryConcludedListener::class,
			[
				'settingsService' => $settings,
				'logger' => new NullLogger(),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end listener()
}//end class
