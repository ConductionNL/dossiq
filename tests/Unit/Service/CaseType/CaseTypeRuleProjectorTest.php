<?php

/**
 * Case type rules projected onto OpenRegister's flow engine.
 *
 * The condition expression is evaluated with OpenRegister's own JSONLogic
 * library when a sibling openregister checkout with its vendor is present, so
 * the test proves the filter keeps the case it should and drops the others.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypeRuleFlowDocument;
use OCA\Dossiq\Service\CaseType\CaseTypeRuleProjector;
use OCA\Dossiq\Service\CaseType\RuleConditionLogic;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Plan, flow shape, condition semantics and the replace-on-publish.
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
class CaseTypeRuleProjectorTest extends TestCase {

	/**
	 * The example rule: when saved, bouwkosten above 100000, add a task.
	 *
	 * @var array<string, mixed>
	 */
	private const WELSTAND = [
		'id' => 'welstandsadvies',
		'name' => 'Welstandsadvies bij grote bouwkosten',
		'enabled' => true,
		'when' => ['event' => 'saved'],
		'conditions' => [['kind' => 'fieldCompare', 'field' => 'bouwkosten', 'op' => 'gt', 'value' => '100000']],
		'actions' => [['action' => 'addTask', 'title' => 'Welstandsadvies vragen']],
	];

	/**
	 * A flow service double recording saves and deletes.
	 *
	 * @var object
	 */
	private object $flows;

	/**
	 * The projector over a case type holding the given rules.
	 *
	 * @param array<int, array<string, mixed>> $rules The rules.
	 * @param array<int, object> $stored Flows already stored.
	 *
	 * @return CaseTypeRuleProjector The projector.
	 */
	private function projector(array $rules, array $stored = []): CaseTypeRuleProjector {
		$store = $this->createMock(CaseTypeStore::class);
		$store->method('readCaseType')->willReturn(['title' => 'Omgevingsvergunning regulier', 'rules' => $rules]);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? ''
		);

		$this->flows = new class($stored) {
			/** @var array<int, array<string, mixed>> */
			public array $saved = [];
			/** @var array<int, string> */
			public array $deleted = [];

			/**
			 * @param array<int, object> $stored Flows already stored.
			 */
			public function __construct(public array $stored) {
			}

			/**
			 * @return array<int, object>
			 */
			public function findAll(?string $app, ?string $slug, ?bool $enabled, int $limit, int $offset): array {
				return array_slice($this->stored, $offset, $limit);
			}

			/**
			 * @param array<string, mixed> $data The flow.
			 * @return object
			 */
			public function save(array $data, ?string $uuid): object {
				$this->saved[] = $data;
				return (object)['notes' => $data['notes']];
			}

			public function delete(string $uuid): void {
				$this->deleted[] = $uuid;
			}
		};

		$flows = $this->flows;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $name) use ($flows): object {
				if ($name === 'OCA\OpenRegister\Service\Flow\FlowService') {
					return $flows;
				}

				if ($name === 'OCA\OpenRegister\Service\Flow\FlowVersionService') {
					return new class {
						public function publish(object $flow): void {
						}
					};
				}

				throw new RuntimeException('no ' . $name);
			}
		);

		return new CaseTypeRuleProjector(
			store: $store,
			settings: $settings,
			documents: new CaseTypeRuleFlowDocument(logic: new RuleConditionLogic()),
			container: $container,
			logger: new NullLogger()
		);
	}//end projector()

	/**
	 * A stored flow carrying a marker.
	 *
	 * @param string $uuid The uuid.
	 * @param string $notes The marker.
	 *
	 * @return object The flow.
	 */
	private static function storedFlow(string $uuid, string $notes): object {
		return new class($uuid, $notes) {
			public function __construct(private string $uuid, private string $notes) {
			}

			public function getUuid(): string {
				return $this->uuid;
			}

			public function getNotes(): string {
				return $this->notes;
			}
		};
	}//end storedFlow()

	/**
	 * An add-task rule is one guarded flow: trigger scoped to the case type,
	 * filter, the create-task step with a once-key, end.
	 *
	 * @return void
	 */
	public function testAnAddTaskRuleIsAGuardedFlow(): void {
		$plan = $this->projector(rules: [self::WELSTAND])->plan(caseTypeId: 'type-1');

		$this->assertSame([], $plan['unprojected']);
		$this->assertCount(1, $plan['flows']);
		$flow = $plan['flows'][0];
		$this->assertSame('dossiq:case-type-rule:type-1:welstandsadvies', $flow['notes']);
		$this->assertSame(
			['openregister.trigger-object', 'openregister.filter', 'dossiq.createTask', 'openregister.end'],
			array_column($flow['nodes'], 'type')
		);
		$this->assertSame(
			['event' => 'object.updated', 'register' => 'dossiq', 'schema' => 'case', 'scope' => ['property' => 'caseType', 'value' => 'type-1']],
			$flow['nodes'][0]['config']
		);
		$this->assertSame(['title' => 'Welstandsadvies vragen', 'onceKey' => 'rule:type-1:welstandsadvies'], $flow['nodes'][2]['config']);
	}//end testAnAddTaskRuleIsAGuardedFlow()

	/**
	 * The filter keeps a case of this type above the threshold, and drops a
	 * case below it and a case of another type.
	 *
	 * @return void
	 */
	public function testTheFilterKeepsOnlyTheCasesTheRuleIsAbout(): void {
		$library = dirname(__DIR__, 5) . '/openregister/vendor/jwadhams/json-logic-php/src/JWadhams/JsonLogic.php';
		if (is_file($library) === false) {
			$this->markTestSkipped('needs a sibling openregister checkout with its vendor, for JSONLogic');
		}

		require_once $library;
		$condition = $this->projector(rules: [self::WELSTAND])->plan(caseTypeId: 'type-1')['flows'][0]['nodes'][1]['config']['condition'];
		$holds = static fn (array $case): bool => (bool)\JWadhams\JsonLogic::apply($condition, ['json' => $case]);

		$answer = static fn (string $type, int $kosten): array => [
			'caseType' => $type,
			'properties' => [['name' => 'oppervlakte', 'value' => 900000], ['name' => 'bouwkosten', 'value' => $kosten]],
		];

		$this->assertTrue($holds($answer('type-1', 150000)));
		$this->assertFalse($holds($answer('type-1', 90000)));
		$this->assertFalse($holds($answer('type-2', 150000)));
		$this->assertTrue($holds(['caseType' => 'type-1', 'bouwkosten' => 150001]));
	}//end testTheFilterKeepsOnlyTheCasesTheRuleIsAbout()

	/**
	 * A status rule adds the status to the guard; a switched-off rule and an
	 * action no flow carries yet are not projected, and the latter is named.
	 *
	 * @return void
	 */
	public function testStatusRulesOffRulesAndUnprojectedActions(): void {
		$status = self::WELSTAND;
		$status['id'] = 'bij-weigering';
		$status['when'] = ['event' => 'statusEntered', 'status' => 'status-geweigerd'];
		$status['conditions'] = [];
		$status['actions'] = [['action' => 'addTask', 'title' => 'Bezwaartermijn bewaken'], ['action' => 'sendMessage', 'emailTemplate' => 'weigering']];
		$off = self::WELSTAND;
		$off['id'] = 'uit';
		$off['enabled'] = false;

		$plan = $this->projector(rules: [$status, $off])->plan(caseTypeId: 'type-1');

		$this->assertCount(1, $plan['flows']);
		$this->assertSame(
			['==' => [['var' => 'json.status'], 'status-geweigerd']],
			$plan['flows'][0]['nodes'][1]['config']['condition']['and'][1]
		);
		$this->assertSame(['bij-weigering: sendMessage'], $plan['unprojected']);
	}//end testStatusRulesOffRulesAndUnprojectedActions()

	/**
	 * Publishing replaces this case type's rule flows and leaves another case type's alone.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
	 */
	public function testPublishReplacesOnlyThisCaseTypesFlows(): void {
		$projector = $this->projector(
			rules: [self::WELSTAND],
			stored: [
				self::storedFlow('old-1', 'dossiq:case-type-rule:type-1:removed-rule'),
				self::storedFlow('other', 'dossiq:case-type-rule:type-2:welstandsadvies'),
				self::storedFlow('hand', 'drawn by hand'),
			]
		);

		$warnings = $projector->publish(caseTypeId: 'type-1');

		$this->assertSame([], $warnings);
		$this->assertSame(['old-1'], $this->flows->deleted);
		$this->assertSame(['dossiq:case-type-rule:type-1:welstandsadvies'], array_column($this->flows->saved, 'notes'));
	}//end testPublishReplacesOnlyThisCaseTypesFlows()
}//end class
