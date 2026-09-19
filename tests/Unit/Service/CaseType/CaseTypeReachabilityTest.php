<?php

/**
 * Publishing refuses a lifecycle a case could never run through.
 *
 * Each case pairs a refusal with an acceptance, because a guard that refuses
 * everything passes every refusal test and makes the fleet unpublishable.
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
 *
 * @spec openspec/specs/case-type-publish-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypeReachability;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the reachability walk publication runs before it writes.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeReachability
 */
class CaseTypeReachabilityTest extends TestCase {

	/**
	 * A three-status lifecycle: received, in progress, done.
	 *
	 * @return array<string, array{title: string, final: bool}> The statuses, by id.
	 */
	private function statuses(): array {
		return [
			's1' => [
				'title' => 'Ontvangen',
				'final' => false,
			],
			's2' => [
				'title' => 'In behandeling',
				'final' => false,
			],
			's3' => [
				'title' => 'Afgehandeld',
				'final' => true,
			],
		];
	}//end statuses()

	/**
	 * The moves that make that lifecycle run end to end.
	 *
	 * @return array<int, array<string, mixed>> The transitions.
	 */
	private function soundMoves(): array {
		return [
			[
				'id' => 't1',
				'label' => 'In behandeling nemen',
				'fromStatus' => 's1',
				'toStatus' => 's2',
			],
			[
				'id' => 't2',
				'label' => 'Afhandelen',
				'fromStatus' => 's2',
				'toStatus' => 's3',
			],
		];
	}//end soundMoves()

	/**
	 * The control. A lifecycle that runs end to end publishes with nothing said.
	 *
	 * Without this, every refusal below would also pass on a guard that
	 * refused every case type there is.
	 *
	 * @return void
	 */
	public function testASoundLifecycleProducesNoFinding(): void {
		$findings = (new CaseTypeReachability())->findings(
			statuses: $this->statuses(),
			initial: 's1',
			moves: $this->soundMoves()
		);

		self::assertSame(expected: [], actual: $findings);
	}//end testASoundLifecycleProducesNoFinding()

	/**
	 * A move with the `*` wildcard is refused, and the refusal names the move.
	 *
	 * Three writers store `*` as "from any status" and no reader honours it:
	 * both readers compare `fromStatus` to the case's status with a strict
	 * equality. The move is authored, exported and never offered.
	 *
	 * @return void
	 */
	public function testAWildcardMoveIsNamedAsUnfireable(): void {
		$moves = $this->soundMoves();
		$moves[] = [
			'id' => 't3',
			'label' => 'Intrekken',
			'fromStatus' => '*',
			'toStatus' => 's3',
		];

		$findings = (new CaseTypeReachability())->findings(
			statuses: $this->statuses(),
			initial: 's1',
			moves: $moves
		);

		self::assertCount(expectedCount: 1, haystack: $findings);
		self::assertStringContainsString(needle: 'Intrekken', haystack: $findings[0]);
		self::assertStringContainsString(needle: 'starts from no status', haystack: $findings[0]);
	}//end testAWildcardMoveIsNamedAsUnfireable()

	/**
	 * An empty `fromStatus` is the same dark move, and is named the same way.
	 *
	 * @return void
	 */
	public function testAMoveWithNoFromStatusIsNamed(): void {
		$findings = (new CaseTypeReachability())->findings(
			statuses: $this->statuses(),
			initial: 's1',
			moves: [
				[
					'id' => 't1',
					'label' => 'Afhandelen',
					'fromStatus' => '',
					'toStatus' => 's3',
				],
			]
		);

		self::assertStringContainsString(needle: 'Afhandelen', haystack: implode(' ', $findings));
	}//end testAMoveWithNoFromStatusIsNamed()

	/**
	 * A move starting from a status this type does not have is named.
	 *
	 * @return void
	 */
	public function testAMoveFromAForeignStatusIsNamed(): void {
		$moves = $this->soundMoves();
		$moves[] = [
			'id' => 't3',
			'label' => 'Heropenen',
			'fromStatus' => 'from-another-case-type',
			'toStatus' => 's2',
		];

		$findings = (new CaseTypeReachability())->findings(
			statuses: $this->statuses(),
			initial: 's1',
			moves: $moves
		);

		self::assertCount(expectedCount: 1, haystack: $findings);
		self::assertStringContainsString(needle: 'Heropenen', haystack: $findings[0]);
		self::assertStringContainsString(
			needle: 'starts from a status this case type does not have',
			haystack: $findings[0]
		);
	}//end testAMoveFromAForeignStatusIsNamed()

	/**
	 * A move leading to a status this type does not have is named.
	 *
	 * @return void
	 */
	public function testAMoveToAForeignStatusIsNamed(): void {
		$moves = $this->soundMoves();
		$moves[] = [
			'id' => 't3',
			'label' => 'Doorzetten',
			'fromStatus' => 's2',
			'toStatus' => 'gone',
		];

		$findings = (new CaseTypeReachability())->findings(
			statuses: $this->statuses(),
			initial: 's1',
			moves: $moves
		);

		self::assertCount(expectedCount: 1, haystack: $findings);
		self::assertStringContainsString(needle: 'Doorzetten', haystack: $findings[0]);
		self::assertStringContainsString(
			needle: 'leads to a status this case type does not have',
			haystack: $findings[0]
		);
	}//end testAMoveToAForeignStatusIsNamed()

	/**
	 * A status nothing leads to is named, not described in general.
	 *
	 * @return void
	 */
	public function testAnOrphanStatusIsNamed(): void {
		$findings = (new CaseTypeReachability())->findings(
			statuses: $this->statuses(),
			initial: 's1',
			moves: [
				[
					'id' => 't1',
					'label' => 'Afhandelen',
					'fromStatus' => 's1',
					'toStatus' => 's3',
				],
			]
		);

		self::assertCount(expectedCount: 1, haystack: $findings);
		self::assertStringContainsString(needle: 'In behandeling', haystack: $findings[0]);
		self::assertStringContainsString(needle: 'No move leads to the status', haystack: $findings[0]);
	}//end testAnOrphanStatusIsNamed()

	/**
	 * One broken move produces one finding, not two.
	 *
	 * The move is the root cause; the status it cannot reach is the symptom.
	 * An administrator told twice fixes it twice.
	 *
	 * @return void
	 */
	public function testABrokenMoveIsNotAlsoReportedAsAnOrphan(): void {
		$findings = (new CaseTypeReachability())->findings(
			statuses: $this->statuses(),
			initial: 's1',
			moves: [
				[
					'id' => 't1',
					'label' => 'In behandeling nemen',
					'fromStatus' => '*',
					'toStatus' => 's2',
				],
				[
					'id' => 't2',
					'label' => 'Afhandelen',
					'fromStatus' => 's1',
					'toStatus' => 's3',
				],
			]
		);

		self::assertCount(expectedCount: 1, haystack: $findings);
		self::assertStringContainsString(needle: 'In behandeling nemen', haystack: $findings[0]);
	}//end testABrokenMoveIsNotAlsoReportedAsAnOrphan()

	/**
	 * A lifecycle with no walkable way to a final status is refused.
	 *
	 * @return void
	 */
	public function testALifecycleThatCanNeverCloseIsRefused(): void {
		$findings = (new CaseTypeReachability())->findings(
			statuses: [
				's1' => [
					'title' => 'Ontvangen',
					'final' => false,
				],
				's2' => [
					'title' => 'In behandeling',
					'final' => false,
				],
				's3' => [
					'title' => 'Afgehandeld',
					'final' => true,
				],
			],
			initial: 's1',
			moves: [
				[
					'id' => 't1',
					'label' => 'In behandeling nemen',
					'fromStatus' => 's1',
					'toStatus' => 's2',
				],
				[
					'id' => 't2',
					'label' => 'Terug naar de balie',
					'fromStatus' => 's2',
					'toStatus' => 's1',
				],
			]
		);

		$closure = array_values(
			array_filter(
				$findings,
				static fn (string $finding): bool => str_contains($finding, 'could never be finished')
			)
		);

		self::assertCount(expectedCount: 1, haystack: $closure);
		self::assertStringContainsString(needle: 'Ontvangen', haystack: $closure[0]);
	}//end testALifecycleThatCanNeverCloseIsRefused()

	/**
	 * A case type with no moves at all says nothing.
	 *
	 * Most case types drive their lifecycle from statuses alone and carry no
	 * workflow template. A finding here would make the fleet unpublishable on
	 * the day this shipped.
	 *
	 * @return void
	 */
	public function testACaseTypeWithNoMovesIsNotRefused(): void {
		$findings = (new CaseTypeReachability())->findings(
			statuses: $this->statuses(),
			initial: 's1',
			moves: []
		);

		self::assertSame(expected: [], actual: $findings);
	}//end testACaseTypeWithNoMovesIsNotRefused()

	/**
	 * An initial status the type does not declare is left to `validate()`.
	 *
	 * That refusal already exists and is worded for it. Repeating it here once
	 * per status would bury the one sentence that says what to do.
	 *
	 * @return void
	 */
	public function testAnUnresolvableInitialStatusSaysNothingHere(): void {
		$findings = (new CaseTypeReachability())->findings(
			statuses: $this->statuses(),
			initial: 'not-a-status-of-this-type',
			moves: $this->soundMoves()
		);

		self::assertSame(expected: [], actual: $findings);
	}//end testAnUnresolvableInitialStatusSaysNothingHere()
}//end class
