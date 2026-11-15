<?php

/**
 * TimerFire Unit Tests.
 *
 * Pins which timer firings belong to a dossiq source, what counts as a breach rung, and how a run under the service account reports failure.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Listener\Support\TimerFire;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\OpenRegister\Service\Flow\Timer\EscalationNotice;
use OCA\OpenRegister\Service\Flow\Timer\TimerFiring;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for TimerFire.
 *
 * @covers \OCA\Dossiq\Listener\Support\TimerFire
 */
class TimerFireTest extends TestCase {

	/**
	 * A fired-timer event.
	 *
	 * @param string     $appId    The timer's app.
	 * @param array|null $metadata The timer metadata.
	 * @param string|null $rung    The rung key.
	 *
	 * @return FlowTimerFiredEvent
	 */
	private function event(string $appId, ?array $metadata, ?string $rung = null): FlowTimerFiredEvent {
		$timer = new FlowTimer();
		$timer->setAppId($appId);
		$timer->setMetadata($metadata);

		return new FlowTimerFiredEvent(
			timer: $timer,
			firing: new TimerFiring(kind: FlowTimerFiredEvent::KIND_RUNG, transition: 'escalation:' . (string)$rung, rungKey: $rung),
			notice: new EscalationNotice(recipients: [], priority: null, message: 'Reminder')
		);
	}//end event()

	/**
	 * Only a dossiq timer from the asked source yields its metadata.
	 *
	 * @return void
	 */
	public function testMetadataOnlyForADossiqTimerFromTheSource(): void {
		$meta = ['source' => 'termijn', 'caseId' => 'case-1'];

		$this->assertSame($meta, TimerFire::metadataFrom($this->event('dossiq', $meta), 'termijn'));
		$this->assertNull(TimerFire::metadataFrom($this->event('dossiq', $meta), 'advice'));
		$this->assertNull(TimerFire::metadataFrom($this->event('pipelinq', $meta), 'termijn'));
		$this->assertNull(TimerFire::metadataFrom($this->event('dossiq', null), 'termijn'));
	}//end testMetadataOnlyForADossiqTimerFromTheSource()

	/**
	 * A breach is a rung key starting with slaBreached:, nothing else.
	 *
	 * @return void
	 */
	public function testABreachIsAnSlaBreachedRung(): void {
		$this->assertTrue(TimerFire::isBreach($this->event('dossiq', [], 'slaBreached:intake')));
		$this->assertFalse(TimerFire::isBreach($this->event('dossiq', [], 'reminder:intake')));
		$this->assertFalse(TimerFire::isBreach($this->event('dossiq', [], 'xslaBreached:intake')));
		$this->assertFalse(TimerFire::isBreach($this->event('dossiq', [], null)));
	}//end testABreachIsAnSlaBreachedRung()

	/**
	 * The operation runs under the account; success and an unavailable account
	 * both report no error, any other failure is handed back.
	 *
	 * @return void
	 */
	public function testRunAsAccountReportsOnlyRealFailures(): void {
		$ran = false;
		$operation = static function () use (&$ran): void {
			$ran = true;
		};

		$working = $this->createMock(BackgroundServiceAccount::class);
		$working->expects($this->once())->method('runAsWhenNobodyIsSignedIn')->with($operation)
			->willReturnCallback(static fn (callable $op): mixed => $op());
		$this->assertNull(TimerFire::runAsAccount($working, $operation));
		$this->assertTrue($ran);

		$unavailable = $this->createMock(BackgroundServiceAccount::class);
		$unavailable->method('runAsWhenNobodyIsSignedIn')->willThrowException(new ServiceAccountUnavailableException('none'));
		$this->assertNull(TimerFire::runAsAccount($unavailable, $operation));

		$failure = new RuntimeException('db down');
		$failing = $this->createMock(BackgroundServiceAccount::class);
		$failing->method('runAsWhenNobodyIsSignedIn')->willThrowException($failure);
		$this->assertSame($failure, TimerFire::runAsAccount($failing, $operation));
	}//end testRunAsAccountReportsOnlyRealFailures()
}//end class
