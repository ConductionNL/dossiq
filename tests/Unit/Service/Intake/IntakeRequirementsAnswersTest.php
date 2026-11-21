<?php

/**
 * What counts as an answered intake requirement, and what the refusal says.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Intake
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Intake\IntakeRequirements;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Intake\IntakeRequirements
 * @uses   \OCA\Dossiq\Exception\RefusedException
 */
class IntakeRequirementsAnswersTest extends TestCase {

	/**
	 * A case type that asks for a list field, a flag and a text before creation.
	 *
	 * @var array<string, mixed>
	 */
	private const CASE_TYPE = [
		'intakeRequirements' => [
			'requiredBeforeCreation' => ['subjects', 'urgent', 'summary'],
		],
	];

	/**
	 * A list counts as answered only when it holds something; false and zero are answers.
	 *
	 * @return void
	 */
	public function testAListIsAnsweredOnlyWhenItHoldsSomething(): void {
		$requirements = new IntakeRequirements();

		self::assertSame(
			['subjects'],
			$requirements->missingBeforeCreation(case: ['subjects' => [], 'urgent' => false, 'summary' => 'x'], caseType: self::CASE_TYPE)
		);
		self::assertSame(
			[],
			$requirements->missingBeforeCreation(case: ['subjects' => ['a'], 'urgent' => 0, 'summary' => 'x'], caseType: self::CASE_TYPE)
		);
	}//end testAListIsAnsweredOnlyWhenItHoldsSomething()

	/**
	 * An empty list refuses the create, naming the field, with the unprocessable status.
	 *
	 * @return void
	 */
	public function testAnEmptyListRefusesTheCreate(): void {
		try {
			(new IntakeRequirements())->assertCreatable(case: ['subjects' => [], 'urgent' => true, 'summary' => 'x'], caseType: self::CASE_TYPE);
			self::fail('An empty list must not pass as answered');
		} catch (RefusedException $e) {
			self::assertSame(IntakeRequirements::RULE_MISSING_FIELD, $e->getRule());
			self::assertSame('This case type asks for subjects before the case exists, and the case does not carry it yet.', $e->getSentence());
			self::assertSame(RefusedException::STATUS_UNPROCESSABLE, $e->getStatus());
		}
	}//end testAnEmptyListRefusesTheCreate()

	/**
	 * With nothing missing the sentence says every field is answered.
	 *
	 * @return void
	 */
	public function testTheSentenceForNothingMissing(): void {
		self::assertSame(
			'Every field this case type asks for before the case exists has been answered.',
			(new IntakeRequirements())->sentenceFor(missing: [])
		);
	}//end testTheSentenceForNothingMissing()
}//end class
