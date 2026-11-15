<?php

/**
 * Edge cases of RoutingEngine: a malformed condition, and continuity with a customer in the ranking and its motivation.
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

use DateTimeImmutable;
use OCA\Dossiq\Service\Kcc\RoutingEngine;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RoutingEngine's condition guard and continuity ranking.
 *
 * @covers \OCA\Dossiq\Service\Kcc\RoutingEngine
 * @uses \OCA\Dossiq\Service\Kcc\RoutingRuleOrder
 */
class RoutingEngineContinuityTest extends TestCase {

	/**
	 * A condition that is not a list of fields never matches, even after a matching one.
	 *
	 * @return void
	 */
	public function testAMalformedConditionNeverMatches(): void {
		$engine = new RoutingEngine();
		$now    = new DateTimeImmutable('2026-06-10T10:00:00');
		$moment = ['subject' => 'Paspoort aanvragen'];

		self::assertTrue($engine->ruleMatches(['matchConditions' => [['type' => 'keyword', 'value' => 'paspoort']]], $moment, $now));
		self::assertFalse($engine->ruleMatches(['matchConditions' => [['type' => 'keyword', 'value' => 'paspoort'], 'paspoort']], $moment, $now));
	}//end testAMalformedConditionNeverMatches()

	/**
	 * An agent who spoke the customer before outranks a less busy one, and says so.
	 *
	 * @return void
	 */
	public function testEarlierContactOutranksALighterWorkload(): void {
		$agents = [
			['userRef' => 'anna', 'currentStatus' => 'available', 'team' => 'Burgerzaken', 'currentWorkload' => 2, 'skills' => []],
			['userRef' => 'bram', 'currentStatus' => 'available', 'team' => 'Burgerzaken', 'currentWorkload' => 30, 'lastContactCustomerRef' => 'klant-7'],
		];

		$ranked = (new RoutingEngine())->rankAgents($agents, 'Burgerzaken', ['customerRef' => 'klant-7']);

		self::assertSame(['bram', 'anna'], array_column($ranked, 'userRef'));
		self::assertTrue($ranked[0]['continuity']);
		self::assertFalse($ranked[0]['skillMatch']);
		self::assertSame('bram: 30 open zaken - eerder contact gehad', $ranked[0]['motivation']);
		self::assertSame('anna: 2 open zaken', $ranked[1]['motivation']);
	}//end testEarlierContactOutranksALighterWorkload()

	/**
	 * Without a customer reference nobody has continuity, even with an empty last contact.
	 *
	 * @return void
	 */
	public function testNoCustomerMeansNoContinuity(): void {
		$agents = [['userRef' => 'anna', 'currentStatus' => 'available', 'lastContactCustomerRef' => '']];

		$ranked = (new RoutingEngine())->rankAgents($agents, '', []);

		self::assertFalse($ranked[0]['continuity']);
		self::assertSame('anna: 0 open zaken', $ranked[0]['motivation']);
	}//end testNoCustomerMeansNoContinuity()
}//end class
