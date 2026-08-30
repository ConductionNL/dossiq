<?php

/**
 * Bezwaar lifecycle migration tests.
 *
 * Verifies that the bezwaar AWB state machine is now declared on the schema
 * via x-openregister-lifecycle (consumed by OpenRegister's transition-guard
 * engine) and that the dossiq-supplied guard classes enforce the AWB
 * preconditions OR delegates back to the app via `requires`.
 *
 * The OR engine itself (illegal-transition rejection on saveObject) is unit
 * tested in OpenRegister; here we assert (a) the declarative transition table
 * dossiq ships is internally consistent — a valid sequential AWB step is
 * declared and an out-of-sequence jump is NOT — and (b) the guard seams
 * behave per AWB art. 7:3 / 7:10.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Lifecycle;

use OCA\Dossiq\Lifecycle\BezwaarDeadlineGuard;
use OCA\Dossiq\Lifecycle\HoorzittingAfzienGuard;
use PHPUnit\Framework\TestCase;

/**
 * Test the bezwaar lifecycle declaration + guards.
 *
 * @spec openspec/changes/migrate-status-engine-to-or-lifecycle/tasks.md#P-6.2
 */
final class BezwaarLifecycleTest extends TestCase {

	/**
	 * Decoded x-openregister-lifecycle annotation for the bezwaar schema.
	 *
	 * @var array<string, mixed>
	 */
	private array $lifecycle;

	/**
	 * Load the bezwaar lifecycle annotation from the shipped register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$registerPath = __DIR__ . '/../../../lib/Settings/dossiq_register.json';
		$register = json_decode((string)file_get_contents($registerPath), true);
		$objection = $register['components']['schemas']['objectionProceeding'] ?? [];
		$this->lifecycle = ($objection['configuration']['x-openregister-lifecycle'] ?? []);
	}//end setUp()

	/**
	 * Emulate OR's transition lookup: is moving from $from to $to declared?
	 *
	 * @param string $from Current lifecycle value.
	 * @param string $to Attempted lifecycle value.
	 *
	 * @return bool True when a declared transition allows the move.
	 */
	private function transitionAllowed(string $from, string $to): bool {
		$transitions = ($this->lifecycle['transitions'] ?? []);
		foreach ($transitions as $spec) {
			if (($spec['to'] ?? null) !== $to) {
				continue;
			}

			$fromList = ($spec['from'] ?? []);
			if (is_string($fromList) === true) {
				$fromList = [$fromList];
			}

			if (in_array($from, $fromList, true) === true) {
				return true;
			}
		}

		return false;
	}//end transitionAllowed()

	/**
	 * The schema declares the field + AWB initial state.
	 *
	 * @return void
	 */
	public function testLifecycleDeclaresStatusFieldAndInitialState(): void {
		$this->assertSame('status', ($this->lifecycle['field'] ?? null));
		$this->assertSame('Received', ($this->lifecycle['initial'] ?? null));
	}//end testLifecycleDeclaresStatusFieldAndInitialState()

	/**
	 * Sequential AWB progression is declared (valid transition succeeds).
	 *
	 * @return void
	 */
	public function testSequentialAwbProgressionIsDeclared(): void {
		$this->assertTrue($this->transitionAllowed('Received', 'AdmissibilityCheck'));
		$this->assertTrue($this->transitionAllowed('AdmissibilityCheck', 'In handling'));
		$this->assertTrue($this->transitionAllowed('In handling', 'Hearing planned'));
		$this->assertTrue($this->transitionAllowed('Decision on objection', 'Handled'));
	}//end testSequentialAwbProgressionIsDeclared()

	/**
	 * Out-of-sequence jumps are NOT declared (illegal transition rejected).
	 *
	 * @return void
	 */
	public function testOutOfSequenceTransitionIsNotDeclared(): void {
		// Skipping the ontvankelijkheidstoets is illegal.
		$this->assertFalse($this->transitionAllowed('Received', 'In handling'));
		// Re-opening a closed bezwaar is illegal.
		$this->assertFalse($this->transitionAllowed('Handled', 'In handling'));
	}//end testOutOfSequenceTransitionIsNotDeclared()

	/**
	 * intrekken is accepted from the four open states only.
	 *
	 * @return void
	 */
	public function testIntrekkenAcceptedFromOpenStatesOnly(): void {
		$this->assertTrue($this->transitionAllowed('Received', 'Withdrawn'));
		$this->assertTrue($this->transitionAllowed('AdmissibilityCheck', 'Withdrawn'));
		$this->assertTrue($this->transitionAllowed('In handling', 'Withdrawn'));
		$this->assertTrue($this->transitionAllowed('Hearing planned', 'Withdrawn'));
		// Not from a terminal/late state.
		$this->assertFalse($this->transitionAllowed('Decision on objection', 'Withdrawn'));
	}//end testIntrekkenAcceptedFromOpenStatesOnly()

	/**
	 * The hearing-skip and beslissen transitions declare their guard FQCNs.
	 *
	 * @return void
	 */
	public function testGuardedTransitionsDeclareRequires(): void {
		$transitions = ($this->lifecycle['transitions'] ?? []);
		$this->assertSame(
			'OCA\\Dossiq\\Lifecycle\\HoorzittingAfzienGuard',
			($transitions['hoorzitting_overslaan']['requires'] ?? null)
		);
		$this->assertSame(
			'OCA\\Dossiq\\Lifecycle\\BezwaarDeadlineGuard',
			($transitions['beslissen']['requires'] ?? null)
		);
	}//end testGuardedTransitionsDeclareRequires()

	/**
	 * hoorzitting_overslaan guard blocks when the hearing right is not waived.
	 *
	 * @return void
	 */
	public function testHearingSkipGuardBlocksWhenNotWaived(): void {
		$guard = new HoorzittingAfzienGuard();

		$denied = $guard->check(['hearingWaived' => false], 'hoorzitting_overslaan', 'alice');
		$this->assertFalse($denied->isAllowed());

		$absent = $guard->check([], 'hoorzitting_overslaan', 'alice');
		$this->assertFalse($absent->isAllowed());

		$allowed = $guard->check(['hearingWaived' => true], 'hoorzitting_overslaan', 'alice');
		$this->assertTrue($allowed->isAllowed());
	}//end testHearingSkipGuardBlocksWhenNotWaived()

	/**
	 * Deadline guard denies when the statutory deadline has passed.
	 *
	 * @return void
	 */
	public function testDeadlineGuardBlocksAfterDeadline(): void {
		$guard = new BezwaarDeadlineGuard();

		$past = (new \DateTimeImmutable('today'))->modify('-1 day')->format('Y-m-d');
		$future = (new \DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');

		$this->assertFalse($guard->check(['decisionDeadline' => $past], 'beslissen', 'bob')->isAllowed());
		$this->assertTrue($guard->check(['decisionDeadline' => $future], 'beslissen', 'bob')->isAllowed());
		// No deadline recorded — fail open.
		$this->assertTrue($guard->check([], 'beslissen', 'bob')->isAllowed());
	}//end testDeadlineGuardBlocksAfterDeadline()
}//end class
