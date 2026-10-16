<?php

/**
 * A case type's intake form binding, read as a form mapping.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit
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

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\IntakeFormBinding;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use PHPUnit\Framework\TestCase;

/**
 * The mapping the check judges and the submit writes with.
 *
 * @covers \OCA\Dossiq\Service\Intake\IntakeFormBinding
 * @uses   \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class IntakeFormBindingTest extends TestCase {

	private const TYPE_ID = '6f1d0c7a-52a4-4b61-9d3e-0c2f8a7b1e10';

	private const STATUS_ID = '1c9e4f30-7b2d-4a85-8e6f-3d5a9b0c2e41';

	/**
	 * A case type with a declared mapping.
	 *
	 * @return array<string, mixed> The case type.
	 */
	private static function mappedType(): array {
		return [
			'id' => self::TYPE_ID,
			'intakeFormRef' => 'form-abc',
			'initialStatus' => self::STATUS_ID,
			'intakeFormMapping' => [
				'fields' => [
					['field' => 'Waar gaat het over?', 'property' => 'title', 'type' => 'text'],
					['field' => 'Toelichting', 'property' => 'description', 'type' => 'textarea'],
					['field' => '', 'property' => 'title'],
					'not an entry',
				],
				'fixed' => ['confidentiality' => 'intern', 'caseType' => 'another-type', 'intakeChannel' => 'post'],
			],
		];
	}//end mappedType()

	/**
	 * The declared answers are kept, an entry that names no question is dropped.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function testTheDeclaredAnswersAreTheMappingsFields(): void {
		$mapping = (new IntakeFormBinding())->mappingFor(caseType: self::mappedType());

		self::assertSame(['title', 'description'], array_column($mapping['fields'], 'property'));
		self::assertSame('text', $mapping['fields'][0]['type']);
		self::assertArrayNotHasKey('title', $mapping['fixed'], 'A mapped title is not also fixed.');
	}//end testTheDeclaredAnswersAreTheMappingsFields()

	/**
	 * The binding decides the case type, the channel and the first status, whatever the declaration says.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function testADeclaredFixedValueCannotSendTheCaseElsewhere(): void {
		$binding = new IntakeFormBinding();
		$mapping = $binding->mappingFor(caseType: self::mappedType());

		self::assertSame(self::TYPE_ID, $mapping['fixed']['caseType']);
		self::assertSame(IntakeFormBinding::CHANNEL, $mapping['fixed']['intakeChannel']);
		self::assertSame(self::STATUS_ID, $mapping['fixed']['status']);
		self::assertSame('intern', $mapping['fixed']['confidentiality']);
		self::assertEqualsCanonicalizing(
			['title', 'description', 'confidentiality', 'caseType', 'intakeChannel', 'status'],
			$binding->sourced(mapping: $mapping)
		);
	}//end testADeclaredFixedValueCannotSendTheCaseElsewhere()

	/**
	 * A binding without declared answers names the case after the first answer and lists them all.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function testABindingWithoutAnswersKeepsWhatItDid(): void {
		$binding = new IntakeFormBinding();
		$type = ['@self' => ['id' => self::TYPE_ID], 'intakeFormRef' => ' form-abc '];

		self::assertSame('form-abc', $binding->formRef(caseType: $type));
		self::assertSame('', $binding->formRef(caseType: ['intakeFormRef' => ['x']]));

		$checked = $binding->mappingFor(caseType: $type);
		self::assertSame([], $checked['fields']);
		self::assertSame(IntakeFormBinding::UNTITLED, $checked['fixed']['title']);
		self::assertSame(self::TYPE_ID, $checked['fixed']['caseType']);
		self::assertArrayNotHasKey('status', $checked['fixed']);
		self::assertArrayNotHasKey('description', $checked['fixed']);

		$written = $binding->mappingFor(
			caseType: $type,
			answers: ['Aantal' => 3, 'Waar gaat het over?' => ' Verhuizing ', 'Dagen' => ['ma', 'di']]
		);
		self::assertSame('Verhuizing', $written['fixed']['title']);
		self::assertSame("Aantal: 3\nWaar gaat het over?: Verhuizing\nDagen: ma, di", $written['fixed']['description']);
		self::assertSame(IntakeFormBinding::UNTITLED, $binding->mappingFor(caseType: $type, answers: [])['fixed']['title']);
	}//end testABindingWithoutAnswersKeepsWhatItDid()

	/**
	 * A case type that was never saved still yields a case type value of the right shape.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
	 */
	public function testTheFixedValuesFitTheRealCaseSchema(): void {
		$binding = new IntakeFormBinding();
		$unsaved = $binding->mappingFor(caseType: ['intakeFormRef' => 'form-abc'], answers: ['Vraag' => 'Antwoord']);

		self::assertSame([], (new RealSchemaValidator())->errors(slug: 'case', payload: $unsaved['fixed']));

		$mapped = $binding->mappingFor(caseType: self::mappedType());
		$case = array_merge($mapped['fixed'], ['title' => 'Verhuizing', 'description' => 'Naar Dorpsstraat 12']);
		self::assertSame([], (new RealSchemaValidator())->errors(slug: 'case', payload: $case));
	}//end testTheFixedValuesFitTheRealCaseSchema()
}//end class
