<?php

/**
 * A transition closed to whoever performed an earlier act.
 *
 * The assertion that earns its place is the THIRD one: the same person may
 * approve a case they did not prepare. Without it, a rule that refused the
 * handler on every case would pass the first two tests and read as four eyes
 * working, while actually being a role check written backwards.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Obligations
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
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Obligations;

use OCA\Dossiq\Service\Transitions\FourEyesRule;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\FourEyesRule
 *
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */
class FourEyesTransitionTest extends TestCase {

	/**
	 * The rule under test.
	 *
	 * @var FourEyesRule
	 */
	private FourEyesRule $rule;

	/**
	 * The transition under test: approving is closed to whoever drafted.
	 *
	 * @var array<string, mixed>
	 */
	private array $transition;

	/**
	 * Build the rule. It reads nothing but the arguments it is handed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->rule = new FourEyesRule();
		$this->transition = [
			'label' => 'Approve the decision',
			'notPerformedBy' => ['act' => 'Draft the decision', 'askInstead' => 'the team lead'],
		];
	}//end setUp()

	/**
	 * One status record, as the chain carries it.
	 *
	 * @param string $label The transition label.
	 * @param string $actor Who performed it.
	 * @param string $at    When.
	 *
	 * @return array<string, mixed>
	 */
	private function record(string $label, string $actor, string $at): array {
		return ['transitionLabel' => $label, 'actor' => $actor, 'createdAt' => $at];
	}//end record()

	/**
	 * The author of a decision may not approve it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testTheAuthorOfADecisionMayNotApproveIt(): void {
		$refusal = $this->rule->refusalFor(
			transition: $this->transition,
			records: [$this->record(label: 'Draft the decision', actor: 'saskia', at: '2026-03-03T10:00:00+01:00')],
			userId: 'saskia',
		);

		self::assertNotNull(actual: $refusal);
		self::assertSame(expected: 'Draft the decision', actual: $refusal['act']);
		self::assertSame(expected: 'saskia', actual: $refusal['actor']);
		self::assertStringStartsWith(prefix: '2026-03-03', string: $refusal['at']);
		self::assertSame(expected: 'the team lead', actual: $refusal['askInstead']);
	}//end testTheAuthorOfADecisionMayNotApproveIt()

	/**
	 * A colleague may approve it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testAColleagueMayApproveIt(): void {
		self::assertNull(
			actual: $this->rule->refusalFor(
				transition: $this->transition,
				records: [$this->record(label: 'Draft the decision', actor: 'saskia', at: '2026-03-03T10:00:00+01:00')],
				userId: 'pieter',
			),
		);
	}//end testAColleagueMayApproveIt()

	/**
	 * The same person may approve a case they did not prepare.
	 *
	 * THE ASSERTION THAT SEPARATES A RULE ABOUT AN ACT FROM A RULE ABOUT A
	 * ROLE. Saskia drafted case one, so she is refused there. On case two,
	 * which somebody else drafted, she approves. A role check would refuse her
	 * both times and pass every other test in this file.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testTheSamePersonMayApproveACaseTheyDidNotPrepare(): void {
		self::assertNotNull(
			actual: $this->rule->refusalFor(
				transition: $this->transition,
				records: [$this->record(label: 'Draft the decision', actor: 'saskia', at: '2026-03-03T10:00:00+01:00')],
				userId: 'saskia',
			),
		);

		self::assertNull(
			actual: $this->rule->refusalFor(
				transition: $this->transition,
				records: [$this->record(label: 'Draft the decision', actor: 'pieter', at: '2026-03-04T10:00:00+01:00')],
				userId: 'saskia',
			),
		);
	}//end testTheSamePersonMayApproveACaseTheyDidNotPrepare()

	/**
	 * The LAST performance decides, not the first.
	 *
	 * A decision redrafted twice was prepared by whoever wrote the version
	 * being approved. Reading the first would refuse a colleague who never
	 * touched the current draft, and let its actual author through.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testTheLastPerformanceDecides(): void {
		$records = [
			$this->record(label: 'Draft the decision', actor: 'saskia', at: '2026-03-03T10:00:00+01:00'),
			$this->record(label: 'Approve the decision', actor: 'pieter', at: '2026-03-04T10:00:00+01:00'),
			$this->record(label: 'Draft the decision', actor: 'pieter', at: '2026-03-05T10:00:00+01:00'),
		];

		// Pieter wrote the version on the table, so pieter is refused.
		self::assertNotNull(
			actual: $this->rule->refusalFor(transition: $this->transition, records: $records, userId: 'pieter'),
		);
		// Saskia's draft was superseded, so saskia is not.
		self::assertNull(
			actual: $this->rule->refusalFor(transition: $this->transition, records: $records, userId: 'saskia'),
		);
	}//end testTheLastPerformanceDecides()

	/**
	 * A record written before `actor` existed falls back to its owner.
	 *
	 * Without the fallback the rule binds only cases started after this
	 * change, and a four-eyes rule that quietly does not apply to a year of
	 * history is a rule nobody can rely on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testARecordWithNoActorFallsBackToItsOwner(): void {
		$refusal = $this->rule->refusalFor(
			transition: $this->transition,
			records: [[
				'transitionLabel' => 'Draft the decision',
				'@self' => ['owner' => 'saskia'],
				'createdAt' => '2026-03-03T10:00:00+01:00',
			]],
			userId: 'saskia',
		);

		self::assertNotNull(actual: $refusal);
		self::assertSame(expected: 'saskia', actual: $refusal['actor']);
	}//end testARecordWithNoActorFallsBackToItsOwner()

	/**
	 * An act that was never performed on this case refuses nobody.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testAnActNeverPerformedRefusesNobody(): void {
		self::assertNull(
			actual: $this->rule->refusalFor(
				transition: $this->transition,
				records: [$this->record(label: 'Receive the application', actor: 'saskia', at: '2026-03-01T10:00:00+01:00')],
				userId: 'saskia',
			),
		);
		self::assertNull(
			actual: $this->rule->refusalFor(transition: $this->transition, records: [], userId: 'saskia'),
		);
	}//end testAnActNeverPerformedRefusesNobody()

	/**
	 * A transition that declares no rule refuses nobody, and an anonymous
	 * caller is not matched against a named actor.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testNoDeclarationAndNoIdentityRefuseNobody(): void {
		$records = [$this->record(label: 'Draft the decision', actor: 'saskia', at: '2026-03-03T10:00:00+01:00')];

		self::assertNull(
			actual: $this->rule->refusalFor(transition: ['label' => 'Approve'], records: $records, userId: 'saskia'),
		);
		self::assertNull(
			actual: $this->rule->refusalFor(transition: $this->transition, records: $records, userId: ''),
		);
	}//end testNoDeclarationAndNoIdentityRefuseNobody()

	/**
	 * The act is matched on the label, trimmed and case-insensitively.
	 *
	 * A label is authored by hand in a workflow template and read back out of
	 * stored records; an exact-bytes match would make the rule silently miss
	 * on a trailing space.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
	 */
	public function testTheActMatchesTrimmedAndCaseInsensitively(): void {
		self::assertNotNull(
			actual: $this->rule->refusalFor(
				transition: $this->transition,
				records: [$this->record(label: '  draft the DECISION ', actor: 'saskia', at: '2026-03-03T10:00:00+01:00')],
				userId: 'saskia',
			),
		);
	}//end testTheActMatchesTrimmedAndCaseInsensitively()
}//end class
