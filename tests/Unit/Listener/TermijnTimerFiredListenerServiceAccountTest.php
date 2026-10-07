<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The termijn timer fire writes as the background service account.
 *
 * OpenRegister's FlowTimerWorker is a cron job, so the fire reaches this
 * listener with nobody signed in. The breach rung then flips the instance to
 * exceeded and records the event, and OpenRegister refused both writes as
 * Anonymous: the term stayed "lopend" past its end date. These tests run the
 * real listener, TermijnService, DeadlineEscalationService and
 * DwangsomCalculationService against a register that refuses a write from
 * nobody, the way OpenRegister does.
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

use OCA\Dossiq\Listener\TermijnTimerFiredListener;
use OCA\Dossiq\Service\CasePriorityRaiseService;
use OCA\Dossiq\Service\DeadlineEscalationService;
use OCA\Dossiq\Service\DwangsomCalculationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests the identity the timer fire writes as.
 */
class TermijnTimerFiredListenerServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;
	use MakesCaseDateNormaliser;

	/**
	 * The register the services write to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * Reset the session and seed one running term.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->acting = null;
		$this->configuredAccount = 'dossiq-achtergrond';
		$this->adminNotices = 0;
		$this->register = $this->refusingRegister();
		$this->register->seed(
			schema: 'deadlineInstance',
			id: 'ti-1',
			row: [
				'case' => 'Z/2026/L1',
				'deadlineDefinition' => 'td-ov',
				'startDate' => '2026-06-01T10:00:00+00:00',
				'endDateCalculated' => '2026-07-27',
				'endDateCurrent' => '2026-07-27',
				'status' => 'lopend',
				'notificatiesVerstuurd' => [],
				'engineTimerId' => 'timer-1',
			]
		);
	}//end setUp()

	/**
	 * The breach rung lands as the service account when cron fired it.
	 *
	 * @return void
	 */
	public function testTheBreachRungWritesAsTheServiceAccount(): void {
		$this->listener()->handle($this->breachFire());

		$this->assertWroteAsTheServiceAccount(
			register: $this->register,
			schemas: ['deadlineInstance', 'termijnGebeurtenis']
		);
		$this->assertSame('exceeded', $this->register->row(schema: 'deadlineInstance', id: 'ti-1')['status']);
	}//end testTheBreachRungWritesAsTheServiceAccount()

	/**
	 * A handler who is signed in stays the writer: the account never takes over.
	 *
	 * @return void
	 */
	public function testASignedInUserStaysTheWriter(): void {
		$this->acting = $this->backgroundUser(uid: 'behandelaar-1');

		$this->listener()->handle($this->breachFire());

		$this->assertSame([], $this->register->refusals);
		$this->assertSame(['behandelaar-1'], $this->register->writers());
		$this->assertSame('behandelaar-1', $this->actingUid());
	}//end testASignedInUserStaysTheWriter()

	/**
	 * Without an account the fire writes nothing and the admins are told.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheFireWritesNothing(): void {
		$this->configuredAccount = '';

		$this->listener()->handle($this->breachFire());

		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertSame('lopend', $this->register->row(schema: 'deadlineInstance', id: 'ti-1')['status']);
		$this->assertNull($this->acting);
	}//end testWithoutAnAccountTheFireWritesNothing()

	/**
	 * The real listener over the real term services.
	 *
	 * @return TermijnTimerFiredListener The listener.
	 */
	private function listener(): TermijnTimerFiredListener {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'termijn_definitie_schema' => 'deadlineDefinition',
				'termijn_instance_schema' => 'deadlineInstance',
				'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
				'dwangsom_berekening_schema' => 'penaltyPaymentCalculation',
				default => '',
			}
		);

		$logger = new NullLogger();
		$terms = new TermijnService(settingsService: $settings, logger: $logger);

		return $this->buildWith(
			TermijnTimerFiredListener::class,
			[
				'termService' => $terms,
				'escalationService' => new DeadlineEscalationService(
					termService: $terms,
					priorityRaiseService: $this->createMock(CasePriorityRaiseService::class),
					logger: $logger
				),
				'penaltyService' => new DwangsomCalculationService(
					settingsService: $settings,
					logger: $logger,
					dates: $this->caseDatesFrozenAt(),
				),
				'settingsService' => $settings,
				'logger' => $logger,
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end listener()

	/**
	 * The breach rung, as FlowTimerWorker fires it.
	 *
	 * @return FlowTimerFiredEvent The event.
	 */
	private function breachFire(): FlowTimerFiredEvent {
		$timer = new FlowTimer();
		$timer->setUuid('timer-1');
		$timer->setAppId('dossiq');
		$timer->setMetadata(
			[
				'source' => 'dossiq-termijn',
				'kind' => 'beslistermijn',
				'termijnInstanceId' => 'ti-1',
				'caseId' => 'Z/2026/L1',
				'basis' => 'AWB 4:13',
			]
		);

		return new FlowTimerFiredEvent(
			timer: $timer,
			kind: FlowTimerFiredEvent::KIND_RUNG,
			transition: 'escalation:slaBreached:0',
			rungKey: 'slaBreached:0',
			recipients: [['type' => 'user', 'id' => 'handler-1', 'role' => 'handler']],
			priority: 'medium',
			message: null
		);
	}//end breachFire()
}//end class
