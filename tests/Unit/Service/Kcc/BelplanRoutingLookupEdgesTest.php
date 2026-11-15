<?php

/**
 * Edge cases of BelplanRoutingService: plans without a trigger number, and menu selections that resolve to no skill.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use OCA\Dossiq\Service\Kcc\BelplanRoutingService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for BelplanRoutingService's lookups at their edges.
 *
 * @covers \OCA\Dossiq\Service\Kcc\BelplanRoutingService
 */
class BelplanRoutingLookupEdgesTest extends TestCase {

	/**
	 * A plan without a usable trigger number is skipped, not matched by every caller.
	 *
	 * @return void
	 */
	public function testAPlanWithoutATriggerNumberIsSkipped(): void {
		$plans = [
			['id' => 'blank', 'triggerNumber' => ' - '],
			['id' => 'none'],
			['id' => 'main', 'triggerNumber' => '14 078'],
		];

		self::assertSame('main', (new BelplanRoutingService())->getActiveBelplan('+31 14078', $plans)['id']);
		self::assertNull((new BelplanRoutingService())->getActiveBelplan('0201234567', [['id' => 'blank', 'triggerNumber' => '']]));
	}//end testAPlanWithoutATriggerNumberIsSkipped()

	/**
	 * Routing steps that are not a list resolve to no skill.
	 *
	 * @return void
	 */
	public function testStepsThatAreNotAListResolveToNothing(): void {
		self::assertSame('', (new BelplanRoutingService())->resolveVaardigheid(['routingSteps' => 'paspoort'], 1));
	}//end testStepsThatAreNotAListResolveToNothing()

	/**
	 * A menu number outside the steps, or pointing at a malformed step, resolves to no skill.
	 *
	 * @return void
	 */
	public function testANumberOutsideTheStepsResolvesToNothing(): void {
		$plan    = ['routingSteps' => [['label' => 'Paspoort', 'vaardigheid' => 'burgerzaken'], 'kapot']];
		$service = new BelplanRoutingService();

		self::assertSame('burgerzaken', $service->resolveVaardigheid($plan, '1'));
		self::assertSame('', $service->resolveVaardigheid($plan, 2));
		self::assertSame('', $service->resolveVaardigheid($plan, 3));
		self::assertSame('', $service->resolveVaardigheid($plan, 0));
	}//end testANumberOutsideTheStepsResolvesToNothing()

	/**
	 * A label skips malformed steps, matches case-insensitively, and an unknown label resolves to nothing.
	 *
	 * @return void
	 */
	public function testALabelSkipsMalformedStepsAndMayMatchNothing(): void {
		$plan    = ['routingSteps' => ['kapot', ['label' => 'Afval', 'vaardigheid' => 'reiniging'], ['label' => 'Parkeren']]];
		$service = new BelplanRoutingService();

		self::assertSame('reiniging', $service->resolveVaardigheid($plan, 'AFVAL'));
		self::assertSame('', $service->resolveVaardigheid($plan, 'parkeren'));
		self::assertSame('', $service->resolveVaardigheid($plan, 'Belastingen'));
	}//end testALabelSkipsMalformedStepsAndMayMatchNothing()
}//end class
