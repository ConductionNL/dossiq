<?php

/**
 * SubsidieService Unit Tests.
 *
 * Exercises the pure subsidy business logic: the aanvraag status machine,
 * beschikkingnummer generation, AWB beslistermijn math, voorschot-schema
 * reconciliation and conditional release, verplichting tracking and BSN
 * masking. No mock-rigged passes — every assertion checks real behaviour.
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
use OCA\Procest\Service\Subsidie\SubsidieService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Procest\Service\Subsidie\SubsidieService
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-04
 */
class SubsidieServiceTest extends TestCase {

	private SubsidieService $service;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$settings = $this->createMock(SettingsService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new SubsidieService($settings, $logger);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testTransitionGuards(): void {
		$this->assertTrue($this->service->isTransitionAllowed('received', 'in_assessment'));
		$this->assertTrue($this->service->isTransitionAllowed('decision_prepared', 'granted'));
		$this->assertFalse($this->service->isTransitionAllowed('received', 'granted'));
		$this->assertFalse($this->service->isTransitionAllowed('granted', 'in_assessment'));
		$this->assertFalse($this->service->isTransitionAllowed('rejected', 'granted'));
		$this->assertFalse($this->service->isTransitionAllowed('onzin', 'granted'));
	}//end testTransitionGuards()

	/**
	 * @return void
	 */
	public function testBeschikkingnummerFormat(): void {
		$now = new DateTimeImmutable('2026-03-01');
		$this->assertSame('SUB-2026-000042', $this->service->generateBeschikkingnummer(42, $now));
		$this->assertSame('SUB-2026-000001', $this->service->generateBeschikkingnummer(0, $now));
	}//end testBeschikkingnummerFormat()

	/**
	 * @return void
	 */
	public function testBeslistermijnMultiYear(): void {
		$registration = new DateTimeImmutable('2026-01-01');
		$deadline = $this->service->computeBeslistermijn($registration, 13);
		$this->assertSame('2026-04-02', $deadline->format('Y-m-d'));
	}//end testBeslistermijnMultiYear()

	/**
	 * REQ-SUB-001: voorschot-schema must sum to the granted amount.
	 *
	 * @return void
	 */
	public function testVoorschotReconciliation(): void {
		$schema = [
			['date' => '2026-01-15', 'amount' => 120000],
			['date' => '2027-01-15', 'amount' => 120000],
			['date' => '2028-01-15', 'amount' => 120000],
			['date' => '2029-03-01', 'amount' => 90000],
		];
		$this->assertTrue($this->service->voorschotSchemaReconciles($schema, 450000.0));
		$this->assertFalse($this->service->voorschotSchemaReconciles($schema, 400000.0));
	}//end testVoorschotReconciliation()

	/**
	 * REQ-SUB-001: a conditional voorschot only releases once its dependent
	 * tussenrapportage is approved.
	 *
	 * @return void
	 */
	public function testConditionalVoorschotRelease(): void {
		$unconditional = ['amount' => 100, 'voorwaarde' => ''];
		$this->assertTrue($this->service->isVoorschotReleasable($unconditional, []));

		$conditional = ['amount' => 100, 'voorwaarde' => 'tussenrapportage:r-2026-q4'];
		$this->assertFalse($this->service->isVoorschotReleasable($conditional, []));
		$this->assertFalse($this->service->isVoorschotReleasable($conditional, ['r-other']));
		$this->assertTrue($this->service->isVoorschotReleasable($conditional, ['r-2026-q4']));

		// Unknown condition shapes fail closed.
		$unknown = ['amount' => 100, 'voorwaarde' => 'magie'];
		$this->assertFalse($this->service->isVoorschotReleasable($unknown, ['anything']));
	}//end testConditionalVoorschotRelease()

	/**
	 * REQ-SUB-003: unmet verplichtingen surface as korting-grounds.
	 *
	 * @return void
	 */
	public function testUnmetVerplichtingen(): void {
		$verplichtingen = [
			['id' => 'v1', 'status' => 'voldaan'],
			['id' => 'v2', 'status' => 'open'],
			['id' => 'v3', 'status' => 'niet_voldaan'],
			['id' => 'v4'],
		];
		$unmet = $this->service->unmetVerplichtingen($verplichtingen);
		$this->assertCount(3, $unmet);
		$ids = array_map(static fn (array $v): string => (string)$v['id'], $unmet);
		$this->assertSame(['v2', 'v3', 'v4'], $ids);
	}//end testUnmetVerplichtingen()

	/**
	 * Special-category data: BSN is masked to the trailing three digits.
	 *
	 * @return void
	 */
	public function testBsnMasking(): void {
		$this->assertSame('******789', $this->service->maskBsn('123456789'));
		$this->assertSame('******789', $this->service->maskBsn('123.456.789'));
		$this->assertSame('***', $this->service->maskBsn('12'));
	}//end testBsnMasking()
}//end class
