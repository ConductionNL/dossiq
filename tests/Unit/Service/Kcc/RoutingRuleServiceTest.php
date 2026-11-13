<?php

/**
 * RoutingRuleService Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
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

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use OCA\Dossiq\Service\Kcc\RoutingEngine;
use OCA\Dossiq\Service\Kcc\RoutingRuleService;
use OCA\Dossiq\Service\Kcc\RoutingTableEvaluator;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RoutingRuleService, over the real routing engine and
 * routing-table evaluator, with the settings service and the OpenRegister
 * object service standing in for the storage boundary.
 *
 * @covers \OCA\Dossiq\Service\Kcc\RoutingRuleService
 * @uses   \OCA\Dossiq\Service\Kcc\RoutingEngine
 * @uses   \OCA\Dossiq\Service\Kcc\RoutingTableEvaluator
 * @uses   \OCA\Dossiq\Service\Kcc\RoutingRuleOrder
 * @uses   \OCA\Dossiq\Service\Kcc\RuleConditions
 */
class RoutingRuleServiceTest extends TestCase {

	/**
	 * The settings service (register, schema ids and the object service).
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settings;

	/**
	 * Recording stand-in for the OpenRegister object service.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Build the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->objects = new class {
			/**
			 * Objects returned by findAll, per schema id.
			 *
			 * @var array<string, array<int, mixed>>
			 */
			public array $bySchema = [];

			/**
			 * Every call as [method, arguments].
			 *
			 * @var array<int, array{0: string, 1: array}>
			 */
			public array $calls = [];

			/**
			 * List objects of one register/schema.
			 *
			 * @param array $config The query config.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config): array {
				$this->calls[] = ['findAll', $config];
				return ($this->bySchema[(string)$config['filters']['schema']] ?? []);
			}

			/**
			 * Save an object and return what would be stored.
			 *
			 * @param array       $object   The object.
			 * @param string      $register The register.
			 * @param string      $schema   The schema.
			 * @param string|null $uuid     The uuid.
			 *
			 * @return object
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): object {
				$this->calls[] = ['saveObject', [$object, $register, $schema, $uuid]];
				return new class (array_merge(['id' => ($uuid ?? 'new-uuid')], $object)) implements \JsonSerializable {
					/**
					 * @param array $data The stored data.
					 */
					public function __construct(private array $data) {
					}

					/**
					 * @return array
					 */
					public function jsonSerialize(): array {
						return $this->data;
					}
				};
			}

			/**
			 * Delete one object.
			 *
			 * @param string $uuid     The uuid.
			 * @param string $register The register.
			 * @param string $schema   The schema.
			 *
			 * @return void
			 */
			public function deleteObject(string $uuid, string $register, string $schema): void {
				$this->calls[] = ['deleteObject', [$uuid, $register, $schema]];
			}
		};
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return RoutingRuleService
	 */
	private function service(): RoutingRuleService {
		return new RoutingRuleService($this->settings, new RoutingEngine(), new RoutingTableEvaluator());
	}//end service()

	/**
	 * Configure OpenRegister as present with register 5, rule schema 11 and agent schema 12.
	 *
	 * @return void
	 */
	private function configured(): void {
		$this->settings->method('getObjectService')->willReturn($this->objects);
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', '5'],
				['routing_rule_schema', '', '11'],
				['kcc_agent_schema', '', '12'],
			]
		);
	}//end configured()

	/**
	 * A valid rule as the client sends it.
	 *
	 * @param array $overrides Fields to set or override.
	 *
	 * @return array
	 */
	private function ruleInput(array $overrides = []): array {
		return array_merge(
			[
				'name' => '  Paspoort  ',
				'priority' => '3',
				'matchConditions' => [['type' => 'keyword', 'value' => 'paspoort']],
				'assignedDomain' => ' burgerzaken ',
				'assignedTeam' => ' Burgerzaken ',
				'escalationTeam' => ' Frontoffice ',
			],
			$overrides
		);
	}//end ruleInput()

	/**
	 * Listing reads the rule schema of the configured register and serializes the results.
	 *
	 * @return void
	 */
	public function testListRulesReadsTheRuleSchema(): void {
		$this->configured();
		$this->objects->bySchema['11'] = [
			new \ArrayObject(),
			['name' => 'plain array'],
			'not an object',
		];

		$result = $this->service()->listRules();

		$this->assertSame([[], ['name' => 'plain array'], []], $result);
		$this->assertSame([['findAll', ['filters' => ['register' => 5, 'schema' => 11]]]], $this->objects->calls);
	}//end testListRulesReadsTheRuleSchema()

	/**
	 * Creating a rule saves the normalized payload and returns the stored object.
	 *
	 * @return void
	 */
	public function testCreateRuleSavesTheNormalizedPayload(): void {
		$this->configured();
		$input = $this->ruleInput(
			['matchConditions' => [['type' => 'channel', 'value' => 7], 'skipped', ['type' => 'keyword']]]
		);

		$result = $this->service()->createRule($input);

		$expected = [
			'name' => 'Paspoort',
			'priority' => 3,
			'matchConditions' => [['type' => 'channel', 'value' => '7'], ['type' => 'keyword', 'value' => '']],
			'assignedDomain' => 'burgerzaken',
			'assignedTeam' => 'Burgerzaken',
			'escalationTeam' => 'Frontoffice',
			'enabled' => true,
		];
		$this->assertSame([['saveObject', [$expected, '5', '11', null]]], $this->objects->calls);
		$this->assertSame(array_merge(['id' => 'new-uuid'], $expected), $result);
	}//end testCreateRuleSavesTheNormalizedPayload()

	/**
	 * Updating saves under the given uuid, and `enabled` is cast to a boolean.
	 *
	 * @return void
	 */
	public function testUpdateRuleSavesUnderTheUuid(): void {
		$this->configured();

		$result = $this->service()->updateRule('rule-1', $this->ruleInput(['enabled' => 0]));

		$this->assertSame('saveObject', $this->objects->calls[0][0]);
		$this->assertSame('rule-1', $this->objects->calls[0][1][3]);
		$this->assertFalse($this->objects->calls[0][1][0]['enabled']);
		$this->assertSame('rule-1', $result['id']);
	}//end testUpdateRuleSavesUnderTheUuid()

	/**
	 * Deleting passes the uuid, register and rule schema.
	 *
	 * @return void
	 */
	public function testDeleteRuleDeletesFromTheRuleSchema(): void {
		$this->configured();

		$this->service()->deleteRule('rule-9');

		$this->assertSame([['deleteObject', ['rule-9', '5', '11']]], $this->objects->calls);
	}//end testDeleteRuleDeletesFromTheRuleSchema()

	/**
	 * Invalid rules are refused before anything is stored.
	 *
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function invalidRules(): array {
		return [
			'blank name' => [['name' => '   '], 'Rule name is required'],
			'conditions not an array' => [['name' => 'x', 'matchConditions' => 'keyword'], 'matchConditions must be an array'],
			'unknown condition type' => [
				['name' => 'x', 'matchConditions' => [['type' => 'sql', 'value' => '1']]],
				'Invalid condition type: sql',
			],
		];
	}//end invalidRules()

	/**
	 * Each invalid rule raises a bad request with its message and stores nothing.
	 *
	 * @param array  $input   The rule input.
	 * @param string $message The expected message.
	 *
	 * @return void
	 *
	 * @dataProvider invalidRules
	 */
	public function testInvalidRulesAreRefused(array $input, string $message): void {
		$this->configured();

		try {
			$this->service()->createRule($input);
			$this->fail('Expected OCSBadRequestException');
		} catch (OCSBadRequestException $e) {
			$this->assertSame($message, $e->getMessage());
		}

		$this->assertSame([], $this->objects->calls);
	}//end testInvalidRulesAreRefused()

	/**
	 * Without OpenRegister every operation is a bad request.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterTheCallIsRefused(): void {
		$this->settings->method('getObjectService')->willReturn(null);

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$this->service()->listRules();
	}//end testWithoutOpenRegisterTheCallIsRefused()

	/**
	 * An unconfigured schema is a bad request, and nothing is read.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaIsRefused(): void {
		$this->settings->method('getObjectService')->willReturn($this->objects);
		$this->settings->method('getConfigValue')->willReturnMap(
			[
				['register', '', '5'],
				['routing_rule_schema', '', ''],
			]
		);

		try {
			$this->service()->deleteRule('rule-1');
			$this->fail('Expected OCSBadRequestException');
		} catch (OCSBadRequestException $e) {
			$this->assertSame('KCC schema is not configured', $e->getMessage());
		}

		$this->assertSame([], $this->objects->calls);
	}//end testAnUnconfiguredSchemaIsRefused()

	/**
	 * A contact moment no rule matches is reported unmatched, without agents.
	 *
	 * @return void
	 */
	public function testRouteWithoutAMatchingRule(): void {
		$this->configured();
		$this->objects->bySchema['11'] = [$this->storedRule()];

		$result = $this->service()->route(['subject' => 'Kapotte lantaarnpaal', 'summary' => '']);

		$this->assertSame(['matched' => false, 'suggestedAgents' => []], $result);
	}//end testRouteWithoutAMatchingRule()

	/**
	 * A matching rule routes to the available agents of its team, skilled agents first.
	 *
	 * @return void
	 */
	public function testRouteRanksTheAgentsOfTheMatchedTeam(): void {
		$this->configured();
		$this->objects->bySchema['11'] = [$this->storedRule()];
		$this->objects->bySchema['12'] = [
			['userRef' => 'busy', 'team' => 'Burgerzaken', 'currentStatus' => 'available', 'currentWorkload' => 1, 'skills' => []],
			['userRef' => 'expert', 'team' => 'Burgerzaken', 'currentStatus' => 'available', 'currentWorkload' => 4, 'skills' => ['burgerzaken']],
			['userRef' => 'away', 'team' => 'Burgerzaken', 'currentStatus' => 'offline', 'currentWorkload' => 0],
			['userRef' => 'other', 'team' => 'Frontoffice', 'currentStatus' => 'available', 'currentWorkload' => 0],
		];

		$result = $this->service()->route(['subject' => 'Nieuw paspoort', 'summary' => '']);

		$this->assertTrue($result['matched']);
		$this->assertSame('Paspoort', $result['rule']);
		$this->assertSame('burgerzaken', $result['assignedDomain']);
		$this->assertSame('Burgerzaken', $result['assignedTeam']);
		$this->assertFalse($result['escalated']);
		$this->assertSame(['expert', 'busy'], array_column($result['suggestedAgents'], 'userRef'));
	}//end testRouteRanksTheAgentsOfTheMatchedTeam()

	/**
	 * With nobody available in the team, the escalation team's agents are suggested.
	 *
	 * @return void
	 */
	public function testRouteEscalatesWhenTheTeamHasNobodyAvailable(): void {
		$this->configured();
		$this->objects->bySchema['11'] = [$this->storedRule()];
		$this->objects->bySchema['12'] = [
			['userRef' => 'away', 'team' => 'Burgerzaken', 'currentStatus' => 'offline'],
			['userRef' => 'front', 'team' => 'Frontoffice', 'currentStatus' => 'available', 'currentWorkload' => 2],
		];

		$result = $this->service()->route(['subject' => 'paspoort verlopen', 'summary' => '']);

		$this->assertTrue($result['escalated']);
		$this->assertSame('Burgerzaken', $result['assignedTeam']);
		$this->assertSame(['front'], array_column($result['suggestedAgents'], 'userRef'));
	}//end testRouteEscalatesWhenTheTeamHasNobodyAvailable()

	/**
	 * Without an escalation team an empty team stays empty and is not marked escalated.
	 *
	 * @return void
	 */
	public function testRouteWithoutEscalationTeamDoesNotEscalate(): void {
		$this->configured();
		$this->objects->bySchema['11'] = [$this->storedRule(['escalationTeam' => ''])];
		$this->objects->bySchema['12'] = [
			['userRef' => 'front', 'team' => 'Frontoffice', 'currentStatus' => 'available'],
		];

		$result = $this->service()->route(['subject' => 'paspoort', 'summary' => '']);

		$this->assertTrue($result['matched']);
		$this->assertFalse($result['escalated']);
		$this->assertSame([], $result['suggestedAgents']);
	}//end testRouteWithoutEscalationTeamDoesNotEscalate()

	/**
	 * A stored routing rule as findAll returns it.
	 *
	 * @param array $overrides Fields to set or override.
	 *
	 * @return array
	 */
	private function storedRule(array $overrides = []): array {
		return array_merge(
			[
				'name' => 'Paspoort',
				'priority' => 1,
				'enabled' => true,
				'matchConditions' => [['type' => 'keyword', 'value' => 'paspoort']],
				'assignedDomain' => 'burgerzaken',
				'assignedTeam' => 'Burgerzaken',
				'escalationTeam' => 'Frontoffice',
			],
			$overrides
		);
	}//end storedRule()
}//end class
