<?php

/**
 * ContactMomentService Unit Tests
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

use OCA\Procest\Service\Kcc\ContactMomentService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ContactMomentService payload building.
 *
 * @covers \OCA\Procest\Service\Kcc\ContactMomentService
 */
class ContactMomentServiceTest extends TestCase {

	private ContactMomentService $service;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$settings = $this->createMock(SettingsService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new ContactMomentService($settings, $logger);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testBuildPayloadRejectsInvalidChannel(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->service->buildPayload(['channel' => 'pigeon'], 'agent1');
	}//end testBuildPayloadRejectsInvalidChannel()

	/**
	 * @return void
	 */
	public function testBuildPayloadRejectsInvalidDirection(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->service->buildPayload(['channel' => 'phone', 'direction' => 'sideways'], 'agent1');
	}//end testBuildPayloadRejectsInvalidDirection()

	/**
	 * The authenticated agent id always wins over any body-supplied value.
	 *
	 * @return void
	 */
	public function testBuildPayloadUsesAuthenticatedAgent(): void {
		$payload = $this->service->buildPayload(
			['channel' => 'phone', 'kccAgentRef' => 'attacker', 'subject' => 'Paspoort'],
			'agent1'
		);
		$this->assertSame('agent1', $payload['kccAgentRef']);
		$this->assertSame('phone', $payload['channel']);
		$this->assertSame('inbound', $payload['direction']);
		$this->assertSame('open', $payload['outcome']);
		$this->assertArrayHasKey('startedAt', $payload);
	}//end testBuildPayloadUsesAuthenticatedAgent()

	/**
	 * Duration is computed when both timestamps are present.
	 *
	 * @return void
	 */
	public function testBuildPayloadComputesDuration(): void {
		$payload = $this->service->buildPayload(
			[
				'channel' => 'phone',
				'startedAt' => '2026-05-20T09:15:00+00:00',
				'endedAt' => '2026-05-20T09:27:30+00:00',
			],
			'agent1'
		);
		$this->assertSame(750, $payload['durationSeconds']);
	}//end testBuildPayloadComputesDuration()

	/**
	 * Tags are normalised to a list of strings.
	 *
	 * @return void
	 */
	public function testBuildPayloadNormalisesTags(): void {
		$payload = $this->service->buildPayload(
			['channel' => 'email', 'tags' => ['paspoort', 123]],
			'agent1'
		);
		$this->assertSame(['paspoort', '123'], $payload['tags']);
	}//end testBuildPayloadNormalisesTags()

	/**
	 * @return void
	 */
	public function testBuildPayloadRejectsInvalidOutcome(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->service->buildPayload(['channel' => 'phone', 'outcome' => 'banana'], 'agent1');
	}//end testBuildPayloadRejectsInvalidOutcome()
}//end class
