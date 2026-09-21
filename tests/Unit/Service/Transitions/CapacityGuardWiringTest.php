<?php

/**
 * The capacity guard is on every road into a status, and carries the target.
 *
 * 🔴 A GUARD A TEMPLATE HAS TO REMEMBER IS A GUARD THE NEXT CASE TYPE FORGETS.
 * The whole of REQ-STE-40's "the guard SHALL bind every transition into the
 * status, including transitions authored before the capacity was set" is that
 * the engine appends it itself, so a limit cannot be walked around by taking
 * another road into the phase. If the append is dropped the guard still exists,
 * still passes its own unit tests, and enforces nothing anywhere.
 *
 * 🔴 AND IT MUST CARRY `toStatus`. `CapacityGuard` evaluates the status being
 * ENTERED, which is the one thing it cannot read off the case: the case still
 * carries the status it is leaving. A guard entry appended without the key
 * returns `passed: true` for everything, silently, which is the same green as
 * a status that had room.
 *
 * Both are asserted against the SOURCE of the two assemblers rather than by
 * running them, because both need a live register to run at all and what is
 * being asserted is that a line is there.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/status-capacity-limit/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\GuardRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Every transition carries the capacity guard, and the guard carries a target.
 *
 * @coversNothing
 */
class CapacityGuardWiringTest extends TestCase {

	/**
	 * The two places a transition's guard list is assembled.
	 *
	 * One decides whether a move is OFFERED, the other whether it RUNS. A
	 * limit appended to only one of them is a button that says it may be
	 * pressed and a move that is refused, or worse, the other way round.
	 *
	 * @var array<int, string>
	 */
	private const ASSEMBLERS = [
		'lib/Service/StatusTransitionService.php',
		'lib/Service/Transitions/OfferedTransitions.php',
	];

	/**
	 * The repository root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 4);
	}//end root()

	/**
	 * Both assemblers append the capacity guard, with the target status on it.
	 *
	 * @return void
	 */
	public function testBothAssemblersAppendTheGuard(): void {
		// REWRITTEN 2026-09-18 ON THE MERGE WITH `approval-gate`, AND THE
		// INVARIANT GOT STRONGER RATHER THAN WEAKER. This test used to read both
		// files and assert each one appended the capacity guard itself. That
		// change moved the implicit guards into one method both assemblers call,
		// `TransitionSpecReader::guardsWithImplicit()`, so the capacity guard now
		// lives there. Keeping the old assertion would have reddened on a merge
		// that made the thing it protects IMPOSSIBLE to get wrong: with one list,
		// the offer and the move cannot disagree at all.
		//
		// So it asserts that instead: neither assembler builds a list of its own,
		// and the one list appends the capacity guard with the target status.
		foreach (self::ASSEMBLERS as $path) {
			$source = file_get_contents($this->root() . '/' . $path);
			$this->assertNotFalse($source, sprintf('%s must be readable', $path));

			$this->assertStringContainsString(
				'guardsWithImplicit(',
				$source,
				sprintf(
					'%s must take its guards from the one list the offer and the move both read; '
					. 'two lists that must agree is how the board offers a move the engine refuses',
					$path
				)
			);
			$this->assertStringNotContainsString(
				"\$guards[] = ['type' => GuardRegistry::STATUS_CHECKLIST]",
				$source,
				sprintf('%s must not assemble an implicit guard list of its own', $path)
			);
		}

		$reader = file_get_contents($this->root() . '/lib/Service/Transitions/TransitionSpecReader.php');
		$this->assertNotFalse($reader, 'the spec reader must be readable');
		$this->assertStringContainsString(
			'GuardRegistry::STATUS_CAPACITY',
			$reader,
			'the shared guard list must append the capacity guard; a limit only bites on the roads '
			. 'it is appended to, and a transition written before the limit names it nowhere'
		);
		$this->assertStringContainsString(
			"'toStatus' => (string)(\$transition['toStatus'] ?? '')",
			$reader,
			'the guard entry must carry the TARGET status; the case still carries the status it is '
			. 'leaving, and a guard with no target passes everything'
		);
	}//end testBothAssemblersAppendTheGuard()

	/**
	 * The registry answers to the type the assemblers append.
	 *
	 * @return void
	 */
	public function testTheRegistryKnowsTheType(): void {
		$this->assertSame('statusCapacity', GuardRegistry::STATUS_CAPACITY);

		$source = file_get_contents(
			$this->root() . '/lib/Service/Transitions/GuardRegistry.php'
		);
		// An unregistered type is LOGGED and skipped, not refused, so a guard
		// the registry does not know evaluates as nothing at all.
		$this->assertStringContainsString(
			'self::STATUS_CAPACITY => $capacity,',
			$source,
			'the registry must map the type to the evaluator, or the guard is skipped in silence'
		);
	}//end testTheRegistryKnowsTheType()

	/**
	 * The status type can carry a limit at all.
	 *
	 * @return void
	 */
	public function testTheStatusTypeCarriesACapacity(): void {
		$register = json_decode(
			file_get_contents($this->root() . '/lib/Settings/dossiq_register.json'),
			true
		);
		$property = $register['components']['schemas']['statusType']['properties']['capacity'];

		$this->assertSame('integer', $property['type']);
		$this->assertSame(0, $property['default'], 'zero must mean no limit, which is every status today');
		$this->assertSame(0, $property['minimum']);

		// An unknown configuration key is dropped in silence and a register
		// whose version did not move is not imported at all, so the number has
		// to leave the last shipped number behind for any of this to reach an
		// instance.
		//
		// 🔴 THE FLOOR MOVED TO 0.20.1 BECAUSE 0.20.0 COLLIDED. leaf-integrations
		// (#2927) and this change both bumped 0.19.2 to 0.20.0 on their own
		// branches. Two branches writing the SAME new number merge that line
		// with no conflict at all, so nothing anywhere said so, and an instance
		// that had already imported 0.20.0 skipped the 0.20.0 that carried
		// `statusType.capacity`. A floor written against the version a change
		// started from cannot see that; one written against the version the
		// fleet is on can.
		$this->assertTrue(
			version_compare($register['info']['version'], '0.20.0', '>'),
			'the register version must move past 0.20.0, which two changes already took, '
			. 'or ImportHandler skips the import on every instance that has it'
		);
	}//end testTheStatusTypeCarriesACapacity()

	/**
	 * A bulk transition refuses per case, because it applies per case.
	 *
	 * @return void
	 */
	public function testTheBulkActionAppliesPerCase(): void {
		$source = file_get_contents(
			$this->root() . '/lib/BulkAction/TransitionCasesAction.php'
		);

		// REQ-STE-41 needed no code: the action already takes ONE object and
		// answers `refused` with the first failed guard's message, so ten cases
		// into three free places move three and report seven, each with the
		// capacity sentence. The assertion is here so that stays true.
		$this->assertMatchesRegularExpression(
			'/public function apply\(\s*ObjectEntity \$object/',
			$source,
			'the bulk action must apply to one object at a time, or a full status refuses the '
			. 'whole selection instead of the cases past the limit'
		);
		$this->assertStringContainsString(
			'BulkActionResult::refused(rule: $this->firstGuardMessage(',
			$source,
			'a refused case must carry the guard sentence, or the report says nothing about why'
		);
	}//end testTheBulkActionAppliesPerCase()
}//end class
