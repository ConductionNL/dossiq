<?php

/**
 * Why `readAccessSource()` and `worksOnCase()` are still here.
 *
 * 🔴 THE PLATFORM DOES NOT YET REFUSE A CASE READ, AND THE EVIDENCE IS IN
 * OPENREGISTER'S OWN WORDS. `MagicRbacHandler::applyRbacFilters()`:
 *
 *     // If no authorization is configured, the schema is open to all — but an
 *     // individual OBJECT may still declare itself private...
 *     if (empty($authorization) === true) { ...open to non-private rows... }
 *
 * and `hasPermission()` consults `ObjectGrantResolver::isGranted()` — which is
 * where openregister#3873's inherited grants are expanded — ONLY inside the
 * `private` scope branch.
 *
 * dossiq's `case` schema declares no `authorization` block and no private
 * scope. So at the platform level every authenticated user may read every
 * case, and the hierarchy declaration this app ships is consulted on a path
 * dossiq's cases never take. `CaseAccessGuard::hasCaseReadAccess()` is the
 * only thing that refuses.
 *
 * 🔴 DELETING THE WALK TODAY WOULD OPEN EVERY CASE TO EVERY ACCOUNT, AND
 * NOTHING WOULD SAY SO. dossiq's unit tests stub `OCA\OpenRegister\*`, so a
 * guard rewritten to "the platform refused the load" passes green while the
 * live platform refuses nothing. That is the shape of disclosure this test
 * exists to prevent: not a wrong answer, an absent question.
 *
 * 🔑 WHAT THIS TEST IS FOR. It is a RATCHET, not a monument. It fails the day
 * the case schema becomes private-scoped or grows an authorization chain —
 * which is the precondition for the deletion — so whoever makes that change is
 * told, here, that the guard can now go. Until then it fails if the guard is
 * deleted.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseAccessGuard;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The precondition for deleting the app-side read walk.
 *
 * @spec openspec/changes/deelzaken-inherit-the-parent-grants/specs/deelzaak-support/spec.md
 */
class CaseReadGuardStaysUntilThePlatformHoldsItTest extends TestCase {
	/**
	 * The shipped case schema.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function caseSchema(): array {
		$raw = file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json');
		$this->assertIsString($raw, 'the register could not be read');
		$register = (array)json_decode((string)$raw, true);

		return (array)$register['components']['schemas']['case'];
	}//end caseSchema()

	/**
	 * The case schema still configures no authorization, which is what makes
	 * the platform open for this schema.
	 *
	 * WHEN THIS FAILS, READ IT AS GOOD NEWS: the schema now restricts reads,
	 * so `CaseAccessGuard::readAccessSource()` and `worksOnCase()` can be
	 * deleted and `hasCaseReadAccess()` can defer to the platform. Do that in
	 * its own pass, with a live probe as an ordinary user, and delete this
	 * test with them.
	 *
	 * @return void
	 */
	public function testTheCaseSchemaStillLeavesTheReadToThisApp(): void {
		$schema = $this->caseSchema();

		$this->assertArrayNotHasKey(
			'authorization',
			$schema,
			'The case schema now declares an authorization chain. The platform may now be refusing case reads on its own: '
			. 'verify that with a live probe as an ordinary user, then delete CaseAccessGuard::readAccessSource(), '
			. 'worksOnCase() and this test.'
		);

		$configuration = (array)($schema['configuration'] ?? []);
		$scope = json_encode($configuration);
		$this->assertStringNotContainsString(
			'"scope":"private"',
			(string)$scope,
			'The case schema now declares the private scope, which is the path openregister consults object grants on. '
			. 'The inherited-grant expansion from openregister#3873 now applies to cases, so the app-side walk can go.'
		);
	}//end testTheCaseSchemaStillLeavesTheReadToThisApp()

	/**
	 * So the walk is still here, and still reachable from the read check.
	 *
	 * @return void
	 */
	public function testTheReadWalkIsStillPresentAndStillUsed(): void {
		$guard = new ReflectionClass(CaseAccessGuard::class);

		$this->assertTrue($guard->hasMethod('readAccessSource'), 'the read walk was deleted while the platform is still open');
		$this->assertTrue($guard->hasMethod('worksOnCase'), 'the per-case relationship test was deleted while the platform is still open');

		$source = (string)file_get_contents((string)$guard->getFileName());
		$readCheck = substr($source, (int)strpos($source, 'public function hasCaseReadAccess'));
		$readCheck = substr($readCheck, 0, (int)strpos($readCheck, 'end hasCaseReadAccess'));

		// The read check must still ASK, and the ANSWER must be the walk's.
		// The admin branch legitimately returns true, so the assertion is on
		// the verdict the method ends with: a rewrite that returns true
		// outright, or that only tests whether the case loaded, is the
		// fail-open this whole file is about — every case readable by every
		// account, with nothing on screen to show for it.
		$this->assertStringContainsString('readAccessSource(', $readCheck);

		$returns = [];
		foreach (explode("\n", $readCheck) as $line) {
			if (str_contains($line, 'return ') === true) {
				$returns[] = trim($line);
			}
		}

		$this->assertNotSame([], $returns);
		$this->assertStringContainsString('readAccessSource(', (string)end($returns));
	}//end testTheReadWalkIsStillPresentAndStillUsed()

	/**
	 * And mutation still does NOT walk, which is the half openregister#3873
	 * explicitly asks dossiq to keep.
	 *
	 * @return void
	 */
	public function testMutationStillDoesNotWalkTheParentChain(): void {
		$guard = new ReflectionClass(CaseAccessGuard::class);
		$source = (string)file_get_contents((string)$guard->getFileName());

		$mutation = substr($source, (int)strpos($source, 'public function hasCaseMutationAccess'));
		$mutation = substr($mutation, 0, (int)strpos($mutation, 'end hasCaseMutationAccess'));

		// A right to SEE a case is not a right to CHANGE its children. The
		// absence of the call is what pins it, so anything added here that
		// resolves an ancestor is the regression.
		$this->assertStringNotContainsString('readAccessSource(', $mutation);
		$this->assertStringNotContainsString('parentCase', $mutation);
	}//end testMutationStillDoesNotWalkTheParentChain()

	/**
	 * The hierarchy declaration is spelled the way the app that enforces it
	 * spells it, so the day the platform does hold the read, it holds it for
	 * cases too.
	 *
	 * @return void
	 */
	public function testTheEdgeIsDeclaredInTheCanonicalSpelling(): void {
		$hierarchy = (array)$this->caseSchema()['configuration']['x-openregister-hierarchy'];

		// `parent` is openregister's canonical key and wins where both are
		// present; `parentField` stays beside it so an instance still running
		// a pre-#3873 openregister keeps the edge it already understood.
		$this->assertSame('parentCase', $hierarchy['parent']);
		$this->assertSame('parentCase', $hierarchy['parentField']);
		$this->assertSame(['read'], $hierarchy['inheritedVerbs']);
	}//end testTheEdgeIsDeclaredInTheCanonicalSpelling()
}//end class
