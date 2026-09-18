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
		foreach (self::ASSEMBLERS as $path) {
			$source = file_get_contents($this->root() . '/' . $path);
			$this->assertNotFalse($source, sprintf('%s must be readable', $path));

			$this->assertStringContainsString(
				'GuardRegistry::STATUS_CAPACITY',
				$source,
				sprintf(
					'%s must append the capacity guard; a limit only bites on the roads it is '
					. 'appended to, and a transition written before the limit names it nowhere',
					$path
				)
			);
			$this->assertStringContainsString(
				"'toStatus' => (string)(\$transition['toStatus'] ?? '')",
				$source,
				sprintf(
					'%s must put the TARGET status on the guard entry; the case still carries the '
					. 'status it is leaving, and a guard with no target passes everything',
					$path
				)
			);
		}
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
		// to leave 0.19.2 behind for any of this to reach an instance.
		$this->assertTrue(
			version_compare($register['info']['version'], '0.19.2', '>'),
			'the register version must move, or ImportHandler skips the import entirely'
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
