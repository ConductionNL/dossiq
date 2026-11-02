<?php

/**
 * CaseTypeRuleFlowDocument golden test
 *
 * Pins the flow document every rule action becomes, through the projector's
 * plan: the trigger, the filter's condition, the action step, the name, the
 * marker, and the actions that become no flow.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypeRuleFlowDocument;
use OCA\Dossiq\Service\CaseType\CaseTypeRuleProjector;
use OCA\Dossiq\Service\CaseType\RuleConditionLogic;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Golden test for CaseTypeRuleFlowDocument::build(), through CaseTypeRuleProjector::plan().
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeRuleFlowDocument
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeRuleProjector
 * @uses \OCA\Dossiq\Service\CaseType\RuleConditionLogic
 */
class CaseTypeRuleFlowDocumentGoldenTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * The plan of one case type.
	 *
	 * @param array<string, mixed> $world The case type, the settings and the definitions.
	 *
	 * @return array<string, mixed> The plan.
	 */
	private function observe(array $world): array {
		$store = $this->createMock(originalClassName: CaseTypeStore::class);
		$store->method('readCaseType')->willReturn($world['caseType']);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$config = (($world['config'] ?? []) + ['register' => 'cases', 'case_schema' => 'case']);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('blueprintFor')->willReturn(['propertyDefinitions' => [['name' => 'bouwkosten'], ['name' => 'gebied']]]);

		$projector = new CaseTypeRuleProjector(
			store: $store,
			settings: $settings,
			documents: new CaseTypeRuleFlowDocument(logic: new RuleConditionLogic()),
			container: $this->createMock(originalClassName: ContainerInterface::class),
			logger: new NullLogger(),
			resolver: $resolver
		);

		return $projector->plan(caseTypeId: 'ct-1');
	}//end observe()

	/**
	 * Every flow a rule action becomes, across its moments, conditions and action kinds.
	 *
	 * @return void
	 */
	public function testGoldenFlowDocuments(): void {
		$rules = [
			[
				'id' => 'task-on-save',
				'name' => ' Advies bij hoge kosten ',
				'when' => ['event' => 'saved'],
				'conditions' => [['kind' => 'fieldCompare', 'field' => 'bouwkosten', 'op' => 'gt', 'value' => '100000']],
				'actions' => [
					['action' => 'addTask', 'title' => ' Advies vragen ', 'taskType' => ' step-7 '],
					['action' => 'addTask', 'title' => 'Zonder type'],
					['action' => 'addTask', 'title' => '  '],
				],
			],
			[
				'id' => 'on-create',
				'when' => ['event' => 'created'],
				'actions' => [
					['action' => 'sendMessage', 'emailTemplate' => ' welkom ', 'recipientRole' => ' initiator '],
					['action' => 'sendMessage', 'emailTemplate' => 'zonder-rol'],
					['action' => 'sendMessage', 'emailTemplate' => ''],
				],
			],
			[
				'id' => 'on-status',
				'name' => '',
				'when' => ['event' => 'statusEntered', 'status' => 'st-2'],
				'conditions' => [],
				'actions' => [
					['action' => 'sendMessage', 'emailTemplate' => 'besluit', 'recipientRole' => 'initiator'],
					['action' => 'setField', 'field' => ' priority ', 'value' => 'high'],
					['action' => 'setField', 'field' => 'gebied', 'value' => 'centrum'],
					['action' => 'setField', 'field' => 'priority', 'value' => ''],
					['action' => 'setField', 'field' => '', 'value' => 'x'],
					['action' => 'setField', 'field' => 'count', 'value' => 0],
					['action' => 'chooseVariant', 'variant' => ' snel '],
					['action' => 'chooseVariant', 'variant' => ''],
					['action' => 'unknownAction'],
					['title' => 'no action named'],
				],
			],
			[
				'id' => 'status-without-status',
				'when' => ['event' => 'statusEntered'],
				'actions' => [['action' => 'addTask', 'title' => 'Controle']],
			],
			[
				'id' => 'unknown-moment',
				'when' => ['event' => 'deleted'],
				'actions' => [['action' => 'chooseVariant', 'variant' => 'lang']],
			],
			[
				'id' => 'no-moment',
				'actions' => [['action' => 'addTask', 'title' => 'Altijd']],
			],
			[
				'name' => 'Zonder id',
				'actions' => [['action' => 'addTask', 'title' => 'Verloren']],
			],
			[
				'id' => 'switched-off',
				'enabled' => false,
				'actions' => [['action' => 'addTask', 'title' => 'Uit']],
			],
		];
		$worlds = [
			'every action kind' => ['caseType' => ['title' => 'Vergunning', 'rules' => $rules]],
			'a case type without a title' => ['caseType' => ['rules' => [$rules[0]]]],
			'register and schema configured otherwise' => [
				'caseType' => ['title' => 'Vergunning', 'rules' => [$rules[2]]],
				'config' => ['register' => 'other-register', 'case_schema' => 'other-case'],
			],
		];

		$observed = [];
		foreach ($worlds as $name => $world) {
			$observed[$name] = $this->observe(world: $world);
		}

		$this->assertMatchesGolden(name: 'case-type-rule-flow-document', value: $observed);
	}//end testGoldenFlowDocuments()
}//end class
