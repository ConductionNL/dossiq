<?php

/**
 * The shipped AVG case type, and the wiring that makes its declarations reach
 * the engine rather than sit in a file.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Gdpr;

use OCA\Dossiq\Service\Gdpr\DataSubjectRequestCase;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\Transitions\FourEyesRule;
use OCA\Dossiq\Service\Transitions\TransitionPreconditions;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * What `lib/Settings/templates/avg-verzoek.json` declares, and whether anything reads it.
 */
class DataSubjectRequestTemplateTest extends TestCase {

	/**
	 * The shipped template.
	 *
	 * @var array<string, mixed>
	 */
	private array $template = [];

	/**
	 * Load the template that actually ships, not a fixture of it.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$path = __DIR__ . '/../../../../lib/Settings/templates/avg-verzoek.json';
		self::assertFileExists($path);
		$this->template = (array)json_decode((string)file_get_contents($path), true);
	}//end setUp()

	/**
	 * One transition, by its label.
	 *
	 * @param string $label The label.
	 *
	 * @return array<string, mixed>
	 */
	private function transition(string $label): array {
		foreach ((array)($this->template['workflowTemplate']['transitions'] ?? []) as $transition) {
			if ((string)($transition['label'] ?? '') === $label) {
				return (array)$transition;
			}
		}

		self::fail('the template declares no transition labelled ' . $label);
	}//end transition()

	/**
	 * The statutory month rides the case type's own processing deadline, so the
	 * term model binds it and this change declares no clock of its own.
	 *
	 * @return void
	 */
	public function testTheStatutoryMonthIsTheCaseTypesOwnDeadline(): void {
		self::assertSame('P1M', $this->template['caseType']['processingDeadline']);
		self::assertSame('P2M', $this->template['caseType']['extensionPeriod']);

		// The same reader the engine uses turns that into days, so the value
		// shipped here is one the term model can actually bind rather than a
		// duration it reads as zero.
		$reader = (new ReflectionClass(TermDeclarationReader::class))->newInstanceWithoutConstructor();
		self::assertSame(30, $reader->days(value: $this->template['caseType']['processingDeadline']));
		self::assertSame(60, $reader->days(value: $this->template['caseType']['extensionPeriod']));
	}//end testTheStatutoryMonthIsTheCaseTypesOwnDeadline()

	/**
	 * The approving transition is closed to whoever prepared the erasure, and
	 * the act it names is the label the preparing transition actually carries.
	 *
	 * 🔑 TWO SPELLINGS OF ONE ACT IS A FOUR EYES RULE THAT NEVER FIRES.
	 * `FourEyesRule::declaredFor()` returns null for an act it cannot read, and
	 * a null rule is an open transition with nothing on screen to say so.
	 *
	 * @return void
	 */
	public function testTheApprovalIsClosedToThePreparer(): void {
		$approve = $this->transition(label: DataSubjectRequestCase::ACT_APPROVE);
		$rule = (new FourEyesRule())->declaredFor(transition: $approve);

		self::assertNotNull($rule);
		self::assertSame(DataSubjectRequestCase::ACT_PREPARE, $rule['act']);
		self::assertNotSame('', $rule['askInstead']);

		// And the act it names is a transition that exists, spelled the same way.
		self::assertSame(DataSubjectRequestCase::ACT_PREPARE, $this->transition(label: DataSubjectRequestCase::ACT_PREPARE)['label']);
	}//end testTheApprovalIsClosedToThePreparer()

	/**
	 * The verwijdering close declares the erasure guard, and every declaration
	 * it carries is in the vocabulary the engine reads.
	 *
	 * @return void
	 */
	public function testTheVerwijderingCloseDeclaresTheErasureGuard(): void {
		$close = $this->transition(label: 'Verwijdering afronden');
		$kinds = array_column((array)$close['requiresSettled'], 'kind');

		self::assertContains('erasureComplete', $kinds);
		self::assertContains('fieldEquals', $kinds);
		foreach ($kinds as $kind) {
			self::assertContains($kind, TransitionPreconditions::DEPENDENCY_KINDS, $kind . ' is dropped by the engine');
		}
	}//end testTheVerwijderingCloseDeclaresTheErasureGuard()

	/**
	 * Every closing transition is pinned to one request kind, so a verwijdering
	 * cannot be closed down the inzage path and skip the erasure guard.
	 *
	 * @return void
	 */
	public function testEveryCloseIsPinnedToItsRequestKind(): void {
		$closes = [];
		foreach ((array)($this->template['workflowTemplate']['transitions'] ?? []) as $transition) {
			if ((string)($transition['toStatusName'] ?? '') !== 'Afgerond') {
				continue;
			}

			$pinned = '';
			foreach ((array)($transition['requiresSettled'] ?? []) as $dependency) {
				if (($dependency['kind'] ?? '') === 'fieldEquals'
					&& ($dependency['field'] ?? '') === 'dataSubjectRequestType'
				) {
					$pinned = (string)$dependency['value'];
				}
			}

			self::assertNotSame('', $pinned, (string)$transition['label'] . ' closes any request kind');
			$closes[] = $pinned;
		}

		sort($closes);
		self::assertSame(['correctie', 'inzage', 'verwijdering'], $closes);
	}//end testEveryCloseIsPinnedToItsRequestKind()

	/**
	 * Every transition names statuses the template declares, which is what the
	 * activation resolves against. A name with no status is a transition that
	 * is dropped, and a dropped guard is no guard.
	 *
	 * @return void
	 */
	public function testEveryTransitionNamesADeclaredStatus(): void {
		$names = array_column((array)($this->template['statusTypes'] ?? []), 'name');
		self::assertNotSame([], $names);

		foreach ((array)($this->template['workflowTemplate']['transitions'] ?? []) as $transition) {
			self::assertContains((string)$transition['fromStatusName'], $names, (string)$transition['label']);
			self::assertContains((string)$transition['toStatusName'], $names, (string)$transition['label']);
		}
	}//end testEveryTransitionNamesADeclaredStatus()

	/**
	 * The three request kinds the template closes are the three the case schema
	 * allows, so an administrator cannot pick a fourth the workflow cannot end.
	 *
	 * @return void
	 */
	public function testTheRequestKindsMatchTheSchema(): void {
		$fragment = (array)json_decode(
			(string)file_get_contents(__DIR__ . '/../../../../lib/Settings/register.d/54-data-subject-request.json'),
			true
		);
		$enum = $fragment['components']['schemas']['case']['properties']['dataSubjectRequestType']['enum'];

		sort($enum);
		self::assertSame(['correctie', 'inzage', 'verwijdering'], $enum);
	}//end testTheRequestKindsMatchTheSchema()
}//end class
