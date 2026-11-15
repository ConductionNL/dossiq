<?php

/**
 * How one derived status's conditions are read against a case.
 *
 * `fieldEquals` compares the scalar text of a value, booleans spelled out;
 * `fieldPresent` refuses null, false and blank; a field path walks nested
 * objects; `documentPresent` matches a document type on any of the document
 * lists and keys a case may carry. An unmet condition is named by its label,
 * else by what it asked for.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Status
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
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Status;

use OCA\Dossiq\Service\Status\DerivedStatusEvaluator;
use OCA\Dossiq\Service\Status\StatusDeclaration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Status\DerivedStatusEvaluator
 * @uses \OCA\Dossiq\Service\Status\StatusDeclaration
 * @uses \OCA\Dossiq\Service\Support\FilledValue
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
class DerivedStatusEvaluatorTest extends TestCase {

	/**
	 * Evaluate one condition against a case.
	 *
	 * @param array<string, mixed> $condition The declared condition.
	 * @param array<string, mixed> $case      The case.
	 *
	 * @return array{derived: bool, satisfied: bool, unmet: array<int, string>}
	 */
	private function evaluate(array $condition, array $case): array {
		return (new DerivedStatusEvaluator(declaration: new StatusDeclaration()))->evaluate(
			statusType: ['id' => 'decided', 'derivedWhen' => [$condition]],
			case: $case,
		);
	}//end evaluate()

	/**
	 * A status that declares no derivation is not derived.
	 *
	 * @return void
	 */
	public function testAStatusWithoutConditionsIsNotDerived(): void {
		self::assertSame(
			expected: ['derived' => false, 'satisfied' => false, 'unmet' => []],
			actual: (new DerivedStatusEvaluator(declaration: new StatusDeclaration()))->evaluate(
				statusType: ['id' => 'intake'],
				case: ['anything' => 'x'],
			),
		);
	}//end testAStatusWithoutConditionsIsNotDerived()

	/**
	 * The cases a `fieldEquals` condition is read in.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: bool}>
	 */
	public static function fieldEqualsCases(): array {
		return [
			'equal text' => ['granted', 'granted', true],
			'text is trimmed' => ['granted', '  granted ', true],
			'other text' => ['granted', 'refused', false],
			'true is spelled out' => ['true', true, true],
			'false is spelled out' => ['false', false, true],
			'true is not 1' => ['1', true, false],
			'a number as text' => ['3', 3, true],
			'an array is no scalar' => ['', ['granted'], true],
			'an array never equals text' => ['granted', ['granted'], false],
		];
	}//end fieldEqualsCases()

	/**
	 * `fieldEquals` compares the scalar text of the value.
	 *
	 * @param string $expected The declared value.
	 * @param mixed  $actual   The value on the case.
	 * @param bool   $holds    Whether the condition holds.
	 *
	 * @return void
	 */
	#[DataProvider('fieldEqualsCases')]
	public function testFieldEqualsComparesTheScalarText(string $expected, mixed $actual, bool $holds): void {
		$verdict = $this->evaluate(
			condition: ['kind' => 'fieldEquals', 'field' => 'outcome', 'value' => $expected],
			case: ['outcome' => $actual],
		);

		$unmet = [];
		if ($holds === false) {
			$unmet = ['outcome'];
		}

		self::assertTrue(condition: $verdict['derived']);
		self::assertSame(expected: $holds, actual: $verdict['satisfied']);
		self::assertSame(expected: $unmet, actual: $verdict['unmet']);
	}//end testFieldEqualsComparesTheScalarText()

	/**
	 * The cases a `fieldPresent` condition is read in.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function fieldPresentCases(): array {
		return [
			'a value' => ['2026-03-05', true],
			'zero is a value' => [0, true],
			'true' => [true, true],
			'false is absent' => [false, false],
			'null is absent' => [null, false],
			'blank is absent' => ['   ', false],
			'an empty list is absent' => [[], false],
		];
	}//end fieldPresentCases()

	/**
	 * `fieldPresent` refuses null, false and blank values.
	 *
	 * @param mixed $value The value on the case.
	 * @param bool  $holds Whether the condition holds.
	 *
	 * @return void
	 */
	#[DataProvider('fieldPresentCases')]
	public function testFieldPresentRefusesEmptyValues(mixed $value, bool $holds): void {
		$verdict = $this->evaluate(
			condition: ['kind' => 'fieldPresent', 'field' => 'decisionDate'],
			case: ['decisionDate' => $value],
		);

		self::assertSame(expected: $holds, actual: $verdict['satisfied']);
	}//end testFieldPresentRefusesEmptyValues()

	/**
	 * A dotted field walks nested objects, and a broken path is absent.
	 *
	 * @return void
	 */
	public function testADottedFieldWalksNestedObjects(): void {
		$condition = ['kind' => 'fieldEquals', 'field' => 'decision.outcome', 'value' => 'granted'];

		self::assertTrue(
			condition: $this->evaluate(condition: $condition, case: ['decision' => ['outcome' => 'granted']])['satisfied'],
		);
		self::assertFalse(
			condition: $this->evaluate(condition: $condition, case: ['decision' => 'granted'])['satisfied'],
		);
		self::assertFalse(
			condition: $this->evaluate(condition: $condition, case: ['decision' => ['other' => 'granted']])['satisfied'],
		);
	}//end testADottedFieldWalksNestedObjects()

	/**
	 * A field condition without a field never holds.
	 *
	 * @return void
	 */
	public function testAFieldConditionWithoutAFieldNeverHolds(): void {
		$verdict = $this->evaluate(condition: ['kind' => 'fieldPresent', 'label' => 'Something'], case: ['' => 'x']);

		self::assertFalse(condition: $verdict['satisfied']);
		self::assertSame(expected: ['Something'], actual: $verdict['unmet']);
	}//end testAFieldConditionWithoutAFieldNeverHolds()

	/**
	 * A document type matches on any document list and any type key, ignoring case.
	 *
	 * @return void
	 */
	public function testADocumentTypeMatchesOnAnyListAndKey(): void {
		$condition = ['kind' => 'documentPresent', 'documentType' => 'Besluit'];

		foreach (['documents', 'files', 'caseDocuments', 'attachments'] as $list) {
			foreach (['documentType', 'type', 'documentTypeSlug'] as $key) {
				self::assertTrue(
					condition: $this->evaluate(
						condition: $condition,
						case: [$list => ['not-a-document', [$key => ' besluit ']]],
					)['satisfied'],
					message: $list . '.' . $key,
				);
			}
		}

		self::assertFalse(
			condition: $this->evaluate(
				condition: $condition,
				case: ['documents' => [['documentType' => 'Advies']], 'notes' => [['type' => 'Besluit']], 'files' => 'Besluit'],
			)['satisfied'],
		);
	}//end testADocumentTypeMatchesOnAnyListAndKey()

	/**
	 * A document condition without a type never holds, even on a typeless document.
	 *
	 * @return void
	 */
	public function testADocumentConditionWithoutATypeNeverHolds(): void {
		self::assertFalse(
			condition: $this->evaluate(
				condition: ['kind' => 'documentPresent'],
				case: ['documents' => [['documentType' => '']]],
			)['satisfied'],
		);
	}//end testADocumentConditionWithoutATypeNeverHolds()

	/**
	 * Every unmet condition is named, by its label first, else by what it asked for, in order.
	 *
	 * @return void
	 */
	public function testEveryUnmetConditionIsNamed(): void {
		$verdict = (new DerivedStatusEvaluator(declaration: new StatusDeclaration()))->evaluate(
			statusType: [
				'id' => 'decided',
				'derivedWhen' => [
					['kind' => 'documentPresent', 'documentType' => 'Besluit'],
					['kind' => 'fieldPresent', 'field' => 'decisionDate', 'label' => '  Decision date '],
					['kind' => 'fieldEquals', 'field' => 'outcome', 'value' => 'granted'],
					['kind' => 'fieldPresent', 'field' => 'applicant'],
				],
			],
			case: ['applicant' => 'Jan'],
		);

		self::assertTrue(condition: $verdict['derived']);
		self::assertFalse(condition: $verdict['satisfied']);
		self::assertSame(expected: ['Besluit', 'Decision date', 'outcome'], actual: $verdict['unmet']);
	}//end testEveryUnmetConditionIsNamed()
}//end class
