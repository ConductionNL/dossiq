<?php

/**
 * CallbackService Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Kcc
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Kcc;

use OCA\Procest\Service\Kcc\CallbackService;
use OCA\Procest\Service\Kcc\SlaCalculator;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for CallbackService.
 *
 * @covers \OCA\Procest\Service\Kcc\CallbackService
 *
 * @uses \OCA\Procest\Service\Kcc\SlaCalculator
 */
class CallbackServiceTest extends TestCase {

	private CallbackService $service;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$settings = $this->createMock(SettingsService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new CallbackService($settings, new SlaCalculator(), $logger);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testBuildPayloadRequiresPhone(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->service->buildPayload(['reason' => 'x'], 'agent1');
	}//end testBuildPayloadRequiresPhone()

	/**
	 * @return void
	 */
	public function testBuildPayloadDefaultsPreferredAgentToCaller(): void {
		$payload = $this->service->buildPayload(['customerPhone' => '+31612345678'], 'agent1');
		$this->assertSame('agent1', $payload['preferredAgent']);
		$this->assertSame('scheduled', $payload['status']);
		$this->assertSame(0, $payload['attemptCount']);
	}//end testBuildPayloadDefaultsPreferredAgentToCaller()

	/**
	 * @return void
	 */
	public function testBuildPayloadRejectsInvalidScheduledFor(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->service->buildPayload(
			['customerPhone' => '+31612345678', 'scheduledFor' => 'not-a-date'],
			'agent1'
		);
	}//end testBuildPayloadRejectsInvalidScheduledFor()

	/**
	 * A missed attempt increments the counter and sets a backoff retry time.
	 *
	 * @return void
	 */
	public function testApplyAttemptMissedSchedulesRetry(): void {
		$now = new \DateTimeImmutable('2026-05-21T14:30:00+00:00');
		$result = $this->service->applyAttempt(['attemptCount' => 0], false, $now);
		$this->assertSame(1, $result['attemptCount']);
		$this->assertSame('attempted', $result['status']);
		$this->assertNotNull($result['nextAttemptAt']);
	}//end testApplyAttemptMissedSchedulesRetry()

	/**
	 * A successful attempt completes the callback.
	 *
	 * @return void
	 */
	public function testApplyAttemptSuccessCompletes(): void {
		$result = $this->service->applyAttempt(['attemptCount' => 1], true);
		$this->assertSame(2, $result['attemptCount']);
		$this->assertSame('completed', $result['status']);
		$this->assertNull($result['nextAttemptAt']);
	}//end testApplyAttemptSuccessCompletes()

	/**
	 * After the maximum number of attempts the callback fails.
	 *
	 * @return void
	 */
	public function testApplyAttemptFailsAfterMaxAttempts(): void {
		// Already had 2 attempts; this third miss reaches MAX_ATTEMPTS (3).
		$result = $this->service->applyAttempt(['attemptCount' => 2], false);
		$this->assertSame(3, $result['attemptCount']);
		$this->assertSame('failed', $result['status']);
		$this->assertNull($result['nextAttemptAt']);
	}//end testApplyAttemptFailsAfterMaxAttempts()
}//end class
