<?php

/**
 * Voorstel lifecycle migration tests.
 *
 * Verifies the voorstel state machine is declared via x-openregister-lifecycle
 * and that the VoorstelSubmitGuard enforces the submit precondition that OR
 * delegates back to procest through the transition's `requires` reference.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Lifecycle;

use OCA\Procest\Lifecycle\VoorstelSubmitGuard;
use PHPUnit\Framework\TestCase;

/**
 * Test the voorstel lifecycle declaration + submit guard.
 *
 * @spec openspec/changes/migrate-status-engine-to-or-lifecycle/tasks.md#P-6.1
 */
final class VoorstelLifecycleTest extends TestCase {

	/**
	 * Decoded x-openregister-lifecycle annotation for the voorstel schema.
	 *
	 * @var array<string, mixed>
	 */
	private array $lifecycle;

	/**
	 * Load the voorstel lifecycle annotation from the shipped register.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$registerPath = __DIR__ . '/../../../lib/Settings/procest_register.json';
		$register = json_decode((string)file_get_contents($registerPath), true);
		$proposal = $register['components']['schemas']['proposal'] ?? [];
		$this->lifecycle = ($proposal['configuration']['x-openregister-lifecycle'] ?? []);
	}//end setUp()

	/**
	 * Emulate OR's transition lookup.
	 *
	 * @param string $from Current lifecycle value.
	 * @param string $to Attempted lifecycle value.
	 *
	 * @return bool True when a declared transition allows the move.
	 */
	private function transitionAllowed(string $from, string $to): bool {
		foreach (($this->lifecycle['transitions'] ?? []) as $spec) {
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
	 * Valid transition (concept → in_parafering) is declared; invalid one is not.
	 *
	 * @return void
	 */
	public function testValidTransitionDeclaredInvalidRejected(): void {
		$this->assertSame('status', ($this->lifecycle['field'] ?? null));
		$this->assertSame('draft', ($this->lifecycle['initial'] ?? null));
		$this->assertTrue($this->transitionAllowed('draft', 'in_parafering'));
		// A finished proposal cannot jump back into parafering.
		$this->assertFalse($this->transitionAllowed('besloten', 'in_parafering'));
	}//end testValidTransitionDeclaredInvalidRejected()

	/**
	 * The startParafering transition declares the submit guard FQCN.
	 *
	 * @return void
	 */
	public function testStartParaferingDeclaresSubmitGuard(): void {
		$transitions = ($this->lifecycle['transitions'] ?? []);
		$this->assertSame(
			'OCA\\Procest\\Lifecycle\\VoorstelSubmitGuard',
			($transitions['startParafering']['requires'] ?? null)
		);
	}//end testStartParaferingDeclaresSubmitGuard()

	/**
	 * The submit guard blocks when required fields are empty and passes when filled.
	 *
	 * @return void
	 */
	public function testSubmitGuardEnforcesRequiredFields(): void {
		$guard = new VoorstelSubmitGuard();

		// Missing onderwerp.
		$this->assertFalse(
			$guard->check(['subject' => '', 'type' => 'collegeadvies'], 'startParafering', 'carol')->isAllowed()
		);
		// Missing type.
		$this->assertFalse(
			$guard->check(['subject' => 'Kapvergunning', 'type' => ''], 'startParafering', 'carol')->isAllowed()
		);
		// Both present.
		$this->assertTrue(
			$guard->check(['subject' => 'Kapvergunning', 'type' => 'collegeadvies'], 'startParafering', 'carol')->isAllowed()
		);
	}//end testSubmitGuardEnforcesRequiredFields()
}//end class
