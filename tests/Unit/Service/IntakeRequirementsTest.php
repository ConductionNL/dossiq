<?php

/**
 * What a case type asks for before a case of it can exist.
 *
 * Four cases drive this, and they are the four the spec separates. A case type
 * with the default declaration refuses a case with no communication channel. An
 * internal case type that declares neither field creates the same case without
 * complaint. A field required before COMPLETION never refuses a creation. And a
 * refusal names the field, because "two fields are missing" is a sentence a
 * handler cannot act on.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Intake\IntakeRequirements;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the two declared lists and the refusal they produce.
 *
 * @covers \OCA\Dossiq\Service\Intake\IntakeRequirements
 */
class IntakeRequirementsTest extends TestCase {

	/**
	 * A case type carrying the declaration a new case type is created with.
	 *
	 * @var array<string, mixed>
	 */
	private const DEFAULT_CASE_TYPE = [
		'title' => 'Melding',
		'intakeRequirements' => [
			'requiredBeforeCreation' => ['communicationChannel', 'confidentiality'],
		],
	];

	/**
	 * The reader under test.
	 *
	 * @var IntakeRequirements
	 */
	private IntakeRequirements $requirements;

	/**
	 * Build the reader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->requirements = new IntakeRequirements();
	}//end setUp()

	/**
	 * A case type with the default declaration asks for both.
	 *
	 * This is what the register fragment writes onto a NEW case type, so it is
	 * driven as a stored declaration rather than as an assumption the reader
	 * makes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testTheDefaultDeclarationAsksForTheChannelAndTheConfidentiality(): void {
		$declaration = $this->requirements->declarationFor(
			caseType: [
				'title' => 'Melding',
				'intakeRequirements' => [
					'requiredBeforeCreation' => IntakeRequirements::DEFAULT_FOR_A_NEW_CASE_TYPE,
				],
			]
		);

		$this->assertSame(
			['communicationChannel', 'confidentiality'],
			$declaration['requiredBeforeCreation']
		);
		$this->assertSame([], $declaration['requiredBeforeComplete']);
	}//end testTheDefaultDeclarationAsksForTheChannelAndTheConfidentiality()

	/**
	 * 🔴 A CASE TYPE THAT STORED NOTHING ASKS FOR NOTHING.
	 *
	 * The blast radius this pins. Reading an absent declaration as the default
	 * pair would have made every case type on every existing instance demand
	 * two fields the minute this shipped, and none of the five existing
	 * creation paths sends either: the start-case widget, the DSO intake, the
	 * quick actions, the mail intake and the demo seed all write a case with a
	 * title, a type and a date. The schema default puts the pair on a NEW case
	 * type; an existing one is moved onto the list by an administrator.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testACaseTypeThatDeclaredNothingRefusesNothing(): void {
		$declaration = $this->requirements->declarationFor(caseType: ['title' => 'Melding']);

		$this->assertSame([], $declaration['requiredBeforeCreation']);

		// The payload the start-case widget writes, verbatim in shape.
		$this->requirements->assertCreatable(
			case: ['title' => 'Melding', 'caseType' => 'ct-1', 'startDate' => '2026-09-15'],
			caseType: ['title' => 'Melding']
		);

		$this->assertTrue(true, 'An undeclared case type creates the case it always did.');
	}//end testACaseTypeThatDeclaredNothingRefusesNothing()

	/**
	 * A case cannot be created without its channel.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-case-type-declares-what-must-be-answered-before-a-case-exists-req-triage-01
	 */
	public function testCreationIsRefusedWithoutTheCommunicationChannel(): void {
		$case = ['title' => 'Kapotte lantaarnpaal', 'confidentiality' => 'openbaar'];

		$this->expectException(RefusedException::class);
		$this->requirements->assertCreatable(case: $case, caseType: self::DEFAULT_CASE_TYPE);
	}//end testCreationIsRefusedWithoutTheCommunicationChannel()

	/**
	 * The refusal names the field, not the count.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-case-type-declares-what-must-be-answered-before-a-case-exists-req-triage-01
	 */
	public function testTheRefusalNamesTheField(): void {
		$case = ['title' => 'Kapotte lantaarnpaal', 'confidentiality' => 'openbaar'];

		try {
			$this->requirements->assertCreatable(case: $case, caseType: self::DEFAULT_CASE_TYPE);
			$this->fail('The creation should have been refused.');
		} catch (RefusedException $e) {
			$this->assertStringContainsString('communicationChannel', $e->getSentence());
			$this->assertSame(IntakeRequirements::RULE_MISSING_FIELD, $e->getRule());
			$this->assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
		}
	}//end testTheRefusalNamesTheField()

	/**
	 * An internal case type may ask for neither field.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-case-type-declares-what-must-be-answered-before-a-case-exists-req-triage-01
	 */
	public function testAnInternalCaseTypeCreatesWithoutEitherField(): void {
		$caseType = [
			'title' => 'Interne opdracht',
			'intakeRequirements' => ['requiredBeforeCreation' => []],
		];

		$this->requirements->assertCreatable(case: ['title' => 'Verhuizing'], caseType: $caseType);

		$this->assertSame(
			[],
			$this->requirements->missingBeforeCreation(case: ['title' => 'Verhuizing'], caseType: $caseType)
		);
	}//end testAnInternalCaseTypeCreatesWithoutEitherField()

	/**
	 * Required before complete is not required before creation.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-case-type-declares-what-must-be-answered-before-a-case-exists-req-triage-01
	 */
	public function testAFieldRequiredBeforeCompletionDoesNotRefuseTheCreation(): void {
		$caseType = [
			'intakeRequirements' => [
				'requiredBeforeCreation' => [],
				'requiredBeforeComplete' => ['requesterAddress'],
			],
		];
		$case = ['title' => 'Telefonische melding'];

		$this->requirements->assertCreatable(case: $case, caseType: $caseType);

		$this->assertFalse($this->requirements->isComplete(case: $case, caseType: $caseType));
		$this->assertSame(
			['requesterAddress'],
			$this->requirements->missingBeforeComplete(case: $case, caseType: $caseType)
		);
	}//end testAFieldRequiredBeforeCompletionDoesNotRefuseTheCreation()

	/**
	 * A case answering everything is created and reads complete.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-case-type-declares-what-must-be-answered-before-a-case-exists-req-triage-01
	 */
	public function testACaseThatAnswersEverythingIsCreatedAndComplete(): void {
		$caseType = [
			'intakeRequirements' => [
				'requiredBeforeCreation' => ['communicationChannel'],
				'requiredBeforeComplete' => ['requesterAddress'],
			],
		];
		$case = [
			'title' => 'Bezwaar',
			'communicationChannel' => 'https://example.gemeente.nl/portaal',
			'requesterAddress' => 'Dorpsstraat 1',
		];

		$this->requirements->assertCreatable(case: $case, caseType: $caseType);

		$this->assertTrue($this->requirements->isComplete(case: $case, caseType: $caseType));
	}//end testACaseThatAnswersEverythingIsCreatedAndComplete()

	/**
	 * A blank string is not an answer, and a false is.
	 *
	 * A checkbox left off is a decision. An empty text box is not, and reading
	 * them the same way would let a case through on a field nobody filled in.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md#requirement-a-case-type-declares-what-must-be-answered-before-a-case-exists-req-triage-01
	 */
	public function testABlankStringIsNotAnAnswerAndFalseIs(): void {
		$caseType = [
			'intakeRequirements' => ['requiredBeforeCreation' => ['confidentiality', 'urgent']],
		];

		$missing = $this->requirements->missingBeforeCreation(
			case: ['confidentiality' => '   ', 'urgent' => false],
			caseType: $caseType
		);

		$this->assertSame(['confidentiality'], $missing);
	}//end testABlankStringIsNotAnAnswerAndFalseIs()

	/**
	 * A declaration that is not a list is read as no declaration, not the default.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testAMalformedDeclarationIsReadAsNoDeclaration(): void {
		$declaration = $this->requirements->declarationFor(
			caseType: ['intakeRequirements' => 'communicationChannel']
		);

		$this->assertSame([], $declaration['requiredBeforeCreation']);
		$this->assertSame([], $declaration['requiredBeforeComplete']);
	}//end testAMalformedDeclarationIsReadAsNoDeclaration()

	/**
	 * A declared list is cleaned of blanks and repeats.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function testADeclaredListIsCleaned(): void {
		$declaration = $this->requirements->declarationFor(
			caseType: [
				'intakeRequirements' => [
					'requiredBeforeCreation' => [' confidentiality ', '', 'confidentiality', 7],
				],
			]
		);

		$this->assertSame(['confidentiality'], $declaration['requiredBeforeCreation']);
	}//end testADeclaredListIsCleaned()
}//end class
