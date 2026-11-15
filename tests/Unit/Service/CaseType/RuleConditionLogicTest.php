<?php

/**
 * Rule Condition Logic Test
 *
 * Pins the JsonLogic a case type rule's conditions compile to: the case type
 * guard every rule starts with, each condition kind (a field compared on the
 * case or in its properties list, presence, difference, status and document
 * type), numeric strings compared as numbers, and `false` for a condition
 * that cannot be compiled so it never matches.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\RuleConditionLogic;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RuleConditionLogic.
 */
#[CoversClass(RuleConditionLogic::class)]
final class RuleConditionLogicTest extends TestCase {
	/**
	 * The comparison of a field on the case or in its properties list.
	 *
	 * @param string $symbol The JsonLogic operator.
	 * @param string $field  The field.
	 * @param mixed  $value  The compared value.
	 *
	 * @return array<string,mixed>
	 */
	private static function comparison(string $symbol, string $field, mixed $value): array {
		return [
			'or' => [
				[$symbol => [['var' => 'json.' . $field], $value]],
				[
					'some' => [
						['var' => 'json.properties'],
						['and' => [['==' => [['var' => 'name'], $field]], [$symbol => [['var' => 'value'], $value]]]],
					],
				],
			],
		];
	}//end comparison()

	/**
	 * A rule is its case type guard followed by each array condition, in order.
	 *
	 * @return void
	 */
	public function testRuleStartsWithTheCaseTypeGuard(): void {
		$logic = (new RuleConditionLogic())->forRule(
			caseTypeId: 'ct-1',
			conditions: [['kind' => 'statusIs', 'status' => 'open'], 'not a condition', ['kind' => 'unknown']]
		);

		self::assertSame(
			expected: [
				'and' => [
					['==' => [['var' => 'json.caseType'], 'ct-1']],
					['==' => [['var' => 'json.status'], 'open']],
					false,
				],
			],
			actual: $logic
		);
	}//end testRuleStartsWithTheCaseTypeGuard()

	/**
	 * A rule without conditions is the case type guard alone.
	 *
	 * @return void
	 */
	public function testRuleWithoutConditions(): void {
		self::assertSame(
			expected: ['and' => [['==' => [['var' => 'json.caseType'], 'ct-1']]]],
			actual: (new RuleConditionLogic())->forRule(caseTypeId: 'ct-1', conditions: [])
		);
	}//end testRuleWithoutConditions()

	/**
	 * Each comparison operator and the JsonLogic symbol it compiles to.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function operators(): array {
		return [
			'eq'  => ['eq', '=='],
			'neq' => ['neq', '!='],
			'gt'  => ['gt', '>'],
			'gte' => ['gte', '>='],
			'lt'  => ['lt', '<'],
			'lte' => ['lte', '<='],
		];
	}//end operators()

	/**
	 * A field comparison checks the field on the case or in its properties list.
	 *
	 * @param string $operator The condition operator.
	 * @param string $symbol   The JsonLogic symbol.
	 *
	 * @return void
	 */
	#[DataProvider('operators')]
	public function testFieldCompare(string $operator, string $symbol): void {
		self::assertSame(
			expected: self::comparison(symbol: $symbol, field: 'amount', value: 'high'),
			actual: (new RuleConditionLogic())->forCondition(condition: ['kind' => 'fieldCompare', 'field' => 'amount', 'op' => $operator, 'value' => 'high'])
		);
	}//end testFieldCompare()

	/**
	 * Numeric strings are compared as numbers; other values as given; the operator defaults to eq.
	 *
	 * @return void
	 */
	public function testValuesAreTyped(): void {
		$logic = new RuleConditionLogic();

		self::assertSame(expected: self::comparison(symbol: '>', field: 'amount', value: 5000), actual: $logic->forCondition(condition: ['kind' => 'fieldCompare', 'field' => 'amount', 'op' => 'gt', 'value' => '5000']));
		self::assertSame(expected: self::comparison(symbol: '<', field: 'rate', value: 2.5), actual: $logic->forCondition(condition: ['kind' => 'fieldCompare', 'field' => 'rate', 'op' => 'lt', 'value' => '2.5']));
		self::assertSame(expected: self::comparison(symbol: '==', field: 'urgent', value: true), actual: $logic->forCondition(condition: ['kind' => 'fieldCompare', 'field' => 'urgent', 'value' => true]));
		self::assertSame(expected: self::comparison(symbol: '==', field: 'zip', value: null), actual: $logic->forCondition(condition: ['kind' => 'fieldEquals', 'field' => 'zip']));
	}//end testValuesAreTyped()

	/**
	 * fieldEquals is an eq comparison whatever op it carries.
	 *
	 * @return void
	 */
	public function testFieldEqualsIsAlwaysEq(): void {
		self::assertSame(
			expected: self::comparison(symbol: '==', field: 'kind', value: 'bouw'),
			actual: (new RuleConditionLogic())->forCondition(condition: ['kind' => 'fieldEquals', 'field' => 'kind', 'op' => 'gt', 'value' => 'bouw'])
		);
	}//end testFieldEqualsIsAlwaysEq()

	/**
	 * Presence is a truthy field on the case or a truthy value in its properties list.
	 *
	 * @return void
	 */
	public function testFieldPresent(): void {
		self::assertSame(
			expected: [
				'or' => [
					['!!' => [['var' => 'json.applicant']]],
					['some' => [['var' => 'json.properties'], ['and' => [['==' => [['var' => 'name'], 'applicant']], ['!!' => [['var' => 'value']]]]]]],
				],
			],
			actual: (new RuleConditionLogic())->forCondition(condition: ['kind' => 'fieldPresent', 'field' => 'applicant'])
		);
	}//end testFieldPresent()

	/**
	 * A difference holds when neither the case field nor a same-named property equals the value.
	 *
	 * @return void
	 */
	public function testFieldDiffers(): void {
		self::assertSame(
			expected: [
				'and' => [
					['!=' => [['var' => 'json.amount'], 10]],
					['!' => [['some' => [['var' => 'json.properties'], ['and' => [['==' => [['var' => 'name'], 'amount']], ['==' => [['var' => 'value'], 10]]]]]]]],
				],
			],
			actual: (new RuleConditionLogic())->forCondition(condition: ['kind' => 'fieldDiffers', 'field' => 'amount', 'value' => '10'])
		);
	}//end testFieldDiffers()

	/**
	 * A document condition matches a document of that type in any of the document lists,
	 * by documentType, type or documentTypeSlug.
	 *
	 * @return void
	 */
	public function testDocumentPresent(): void {
		$logic = (new RuleConditionLogic())->forCondition(condition: ['kind' => 'documentPresent', 'documentType' => 'bouwtekening']);

		$byType = ['or' => [
			['==' => [['var' => 'documentType'], 'bouwtekening']],
			['==' => [['var' => 'type'], 'bouwtekening']],
			['==' => [['var' => 'documentTypeSlug'], 'bouwtekening']],
		]];
		self::assertSame(
			expected: ['or' => [
				['some' => [['var' => 'json.documents'], $byType]],
				['some' => [['var' => 'json.files'], $byType]],
				['some' => [['var' => 'json.caseDocuments'], $byType]],
				['some' => [['var' => 'json.attachments'], $byType]],
			]],
			actual: $logic
		);
	}//end testDocumentPresent()

	/**
	 * Conditions that cannot be compiled, and why.
	 *
	 * @return array<string, array{0: array<string,mixed>}>
	 */
	public static function uncompilable(): array {
		return [
			'unknown kind'            => [['kind' => 'magic', 'field' => 'x']],
			'no kind'                 => [['field' => 'x']],
			'compare without field'   => [['kind' => 'fieldCompare', 'op' => 'eq', 'value' => 1]],
			'unknown operator'        => [['kind' => 'fieldCompare', 'field' => 'x', 'op' => 'like', 'value' => 1]],
			'equals without field'    => [['kind' => 'fieldEquals', 'value' => 1]],
			'present without field'   => [['kind' => 'fieldPresent']],
			'differs without field'   => [['kind' => 'fieldDiffers', 'value' => 1]],
			'document without a type' => [['kind' => 'documentPresent']],
		];
	}//end uncompilable()

	/**
	 * A condition that cannot be compiled is false, so the rule never matches.
	 *
	 * @param array<string,mixed> $condition The condition.
	 *
	 * @return void
	 */
	#[DataProvider('uncompilable')]
	public function testUncompilableConditionIsFalse(array $condition): void {
		self::assertFalse(condition: (new RuleConditionLogic())->forCondition(condition: $condition));
	}//end testUncompilableConditionIsFalse()

	/**
	 * A status condition without a status compares against the empty status.
	 *
	 * @return void
	 */
	public function testStatusIsWithoutStatus(): void {
		self::assertSame(
			expected: ['==' => [['var' => 'json.status'], '']],
			actual: (new RuleConditionLogic())->forCondition(condition: ['kind' => 'statusIs'])
		);
	}//end testStatusIsWithoutStatus()
}//end class
