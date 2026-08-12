<?php

/**
 * RoutingEngine Unit Tests
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

use OCA\Procest\Service\Kcc\RoutingEngine;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoutingEngine.
 *
 * @covers \OCA\Procest\Service\Kcc\RoutingEngine
 */
class RoutingEngineTest extends TestCase {

	private RoutingEngine $engine;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->engine = new RoutingEngine();
	}//end setUp()

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function sampleRules(): array {
		return [
			[
				'name' => 'Paspoort → Burgerzaken',
				'priority' => 1,
				'enabled' => true,
				'matchConditions' => [['type' => 'keyword', 'value' => 'paspoort']],
				'assignedDomain' => 'burgerzaken',
				'assignedTeam' => 'Burgerzaken',
				'escalationTeam' => 'Frontoffice',
			],
			[
				'name' => 'Openbare Werken → OBR',
				'priority' => 2,
				'enabled' => true,
				'matchConditions' => [['type' => 'keyword', 'value' => 'lantaarnpaal']],
				'assignedDomain' => 'openbare_werken',
				'assignedTeam' => 'Beheer Openbare Ruimte',
			],
			[
				'name' => 'WMO → Sociaal Domein',
				'priority' => 3,
				'enabled' => true,
				'matchConditions' => [['type' => 'regex', 'value' => 'WMO.*verzoek']],
				'assignedDomain' => 'wmo',
				'assignedTeam' => 'Sociaal Domein',
			],
		];
	}//end sampleRules()

	/**
	 * @return void
	 */
	public function testKeywordRouting(): void {
		$result = $this->engine->evaluate(
			$this->sampleRules(),
			['subject' => 'Kapotte lantaarnpaal Hoofdstraat 24']
		);
		$this->assertNotNull($result);
		$this->assertSame('Beheer Openbare Ruimte', $result['assignedTeam']);
		$this->assertSame('openbare_werken', $result['assignedDomain']);
	}//end testKeywordRouting()

	/**
	 * @return void
	 */
	public function testRegexRouting(): void {
		$result = $this->engine->evaluate(
			$this->sampleRules(),
			['subject' => 'WMO verzoek hulp huishouden']
		);
		$this->assertNotNull($result);
		$this->assertSame('Sociaal Domein', $result['assignedTeam']);
	}//end testRegexRouting()

	/**
	 * The lowest-priority-number matching rule wins.
	 *
	 * @return void
	 */
	public function testPriorityConflictResolution(): void {
		$result = $this->engine->evaluate(
			$this->sampleRules(),
			['subject' => 'Paspoort en lantaarnpaal melding']
		);
		$this->assertNotNull($result);
		$this->assertSame('Burgerzaken', $result['assignedTeam']);
	}//end testPriorityConflictResolution()

	/**
	 * @return void
	 */
	public function testNoMatchReturnsNull(): void {
		$result = $this->engine->evaluate(
			$this->sampleRules(),
			['subject' => 'Iets totaal anders']
		);
		$this->assertNull($result);
	}//end testNoMatchReturnsNull()

	/**
	 * Disabled rules are skipped.
	 *
	 * @return void
	 */
	public function testDisabledRuleSkipped(): void {
		$rules = $this->sampleRules();
		$rules[0]['enabled'] = false;
		$result = $this->engine->evaluate($rules, ['subject' => 'paspoort verlenging']);
		$this->assertNull($result);
	}//end testDisabledRuleSkipped()

	/**
	 * Time-of-day condition matches after the boundary.
	 *
	 * @return void
	 */
	public function testTimeOfDayRouting(): void {
		$rules = [
			[
				'name' => 'Avond → escalatie',
				'priority' => 1,
				'enabled' => true,
				'matchConditions' => [
					['type' => 'channel', 'value' => 'phone'],
					['type' => 'time_of_day', 'value' => 'after_17:00'],
				],
				'assignedTeam' => 'Avonddienst',
			],
		];

		$evening = new \DateTimeImmutable('2026-05-20T17:15:00+00:00');
		$morning = new \DateTimeImmutable('2026-05-20T09:15:00+00:00');

		$this->assertNotNull($this->engine->evaluate($rules, ['channel' => 'phone'], $evening));
		$this->assertNull($this->engine->evaluate($rules, ['channel' => 'phone'], $morning));
	}//end testTimeOfDayRouting()

	/**
	 * Customer-type condition distinguishes businesses (8-digit KvK).
	 *
	 * @return void
	 */
	public function testCustomerTypeRouting(): void {
		$rules = [
			[
				'name' => 'Bedrijven → Ondernemersplein',
				'priority' => 1,
				'enabled' => true,
				'matchConditions' => [['type' => 'customer_type', 'value' => 'bedrijf']],
				'assignedTeam' => 'Ondernemersplein',
			],
		];

		$business = ['customerRef' => '12345678', 'subject' => 'vraag'];
		$citizen = ['customerRef' => '123456789', 'subject' => 'vraag'];

		$this->assertNotNull($this->engine->evaluate($rules, $business));
		$this->assertNull($this->engine->evaluate($rules, $citizen));
	}//end testCustomerTypeRouting()

	/**
	 * Agents are ranked by lowest workload, with skill match boosting.
	 *
	 * @return void
	 */
	public function testAgentRankingByWorkloadAndSkill(): void {
		$agents = [
			['userRef' => 'a', 'currentStatus' => 'available', 'team' => 'Burgerzaken', 'currentWorkload' => 8, 'skills' => ['wmo']],
			['userRef' => 'b', 'currentStatus' => 'available', 'team' => 'Burgerzaken', 'currentWorkload' => 5, 'skills' => ['paspoort']],
			['userRef' => 'c', 'currentStatus' => 'available', 'team' => 'Burgerzaken', 'currentWorkload' => 12, 'skills' => ['burgerzaken']],
			['userRef' => 'd', 'currentStatus' => 'offline', 'team' => 'Burgerzaken', 'currentWorkload' => 1, 'skills' => []],
		];

		$ranked = $this->engine->rankAgents($agents, 'Burgerzaken', ['tags' => ['paspoort']]);

		// Offline agent excluded.
		$this->assertCount(3, $ranked);
		// 'b' has a skill match on the 'paspoort' tag -> ranks first.
		$this->assertSame('b', $ranked[0]['userRef']);
		$this->assertTrue($ranked[0]['skillMatch']);
		$this->assertStringContainsString('open zaken', $ranked[0]['motivation']);
	}//end testAgentRankingByWorkloadAndSkill()

	/**
	 * Agents outside the assigned team are excluded.
	 *
	 * @return void
	 */
	public function testAgentRankingFiltersTeam(): void {
		$agents = [
			['userRef' => 'a', 'currentStatus' => 'available', 'team' => 'Burgerzaken', 'currentWorkload' => 3],
			['userRef' => 'x', 'currentStatus' => 'available', 'team' => 'OBR', 'currentWorkload' => 1],
		];

		$ranked = $this->engine->rankAgents($agents, 'Burgerzaken', []);
		$this->assertCount(1, $ranked);
		$this->assertSame('a', $ranked[0]['userRef']);
	}//end testAgentRankingFiltersTeam()
}//end class
