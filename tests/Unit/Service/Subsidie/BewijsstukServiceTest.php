<?php

/**
 * BewijsstukService Unit Tests.
 *
 * Exercises evidence-document handling (REQ-SUB-007): the per-phase type
 * whitelist, retention assignment, SHA-256 hashing/verification, retention
 * end-date math and immutability guarding.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Subsidie
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

namespace OCA\Procest\Tests\Unit\Service\Subsidie;

use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Subsidie\BewijsstukService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\Subsidie\BewijsstukService
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-21
 */
class BewijsstukServiceTest extends TestCase {

	private BewijsstukService $service;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$settings = $this->createMock(SettingsService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new BewijsstukService($settings, $logger);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testTypeWhitelist(): void {
		$this->assertTrue($this->service->isTypeAllowed('request', 'projectplan'));
		$this->assertTrue($this->service->isTypeAllowed('determination', 'auditorsStatement'));
		// accountantsverklaring is not an aanvraag-phase document.
		$this->assertFalse($this->service->isTypeAllowed('request', 'auditorsStatement'));
		$this->assertFalse($this->service->isTypeAllowed('unknown', 'projectplan'));
	}//end testTypeWhitelist()

	/**
	 * @return void
	 */
	public function testRetentionDefaultsAndOverride(): void {
		$this->assertSame(7, $this->service->bewaartermijnJaren('request'));
		$this->assertSame(10, $this->service->bewaartermijnJaren('determination'));
		// Regeling override wins.
		$this->assertSame(15, $this->service->bewaartermijnJaren('request', 15));
		// Zero/negative override is ignored.
		$this->assertSame(7, $this->service->bewaartermijnJaren('request', 0));
	}//end testRetentionDefaultsAndOverride()

	/**
	 * @return void
	 */
	public function testRetentionEndDate(): void {
		$from = new DateTimeImmutable('2026-06-01');
		$this->assertSame('2033-06-01', $this->service->bewaartermijnEinde($from, 7)->format('Y-m-d'));
	}//end testRetentionEndDate()

	/**
	 * @return void
	 */
	public function testHashRoundTrip(): void {
		$contents = 'projectplan v1';
		$hash = $this->service->computeHash($contents);
		$this->assertSame(64, strlen($hash));
		$this->assertTrue($this->service->verifyHash($contents, $hash));
		$this->assertFalse($this->service->verifyHash('tampered', $hash));
	}//end testHashRoundTrip()

	/**
	 * REQ-SUB-007: immutability guard rejects mutating a vaststelling-linked
	 * bewijsstuk.
	 *
	 * @return void
	 */
	public function testImmutabilityGuard(): void {
		// Mutable document passes.
		$this->service->assertMutable(['immutable' => false]);
		$this->expectException(OCSBadRequestException::class);
		$this->service->assertMutable(['immutable' => true]);
	}//end testImmutabilityGuard()
}//end class
