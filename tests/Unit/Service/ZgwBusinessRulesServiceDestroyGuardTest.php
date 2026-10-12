<?php

/**
 * The case type delete guard of the ZGW business rules
 *
 * A case type with active cases is a conflict, one with closed cases asks for
 * confirmation, and a delete check that could not run is refused as unverified.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Zgw\ZgwRulesDispatcher;
use OCA\Dossiq\Service\ZgwBusinessRulesService;
use OCA\Dossiq\Service\ZgwZtcRulesService;
use PHPUnit\Framework\TestCase;

/**
 * What the delete of a case type answers per verdict of the delete check.
 *
 * @covers \OCA\Dossiq\Service\ZgwBusinessRulesService
 * @uses \OCA\Dossiq\Service\Zgw\ZgwRuleResults
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZgwBusinessRulesServiceDestroyGuardTest extends TestCase {
	/**
	 * How often the delete went on to the per-register rules.
	 *
	 * @var int
	 */
	private int $dispatched = 0;

	/**
	 * The answer of deleting case type ct-1 for one verdict of the delete check.
	 *
	 * @param array $verdict What the delete check answers.
	 * @param array $body The request body.
	 *
	 * @return array
	 */
	private function destroy(array $verdict, array $body = []): array {
		$ztc = $this->createMock(ZgwZtcRulesService::class);
		$ztc->method('checkConceptProtection')->willReturn(null);
		$ztc->method('validateDeletion')->with('reg', 'ct-1')->willReturn($verdict);

		$dispatcher = $this->createMock(ZgwRulesDispatcher::class);
		$dispatcher->method('dispatch')->willReturnCallback(
			function () use ($body): array {
				$this->dispatched++;
				return ['valid' => true, 'status' => 200, 'detail' => '', 'enrichedBody' => $body];
			}
		);

		return (new ZgwBusinessRulesService(ztcRules: $ztc, dispatcher: $dispatcher))->validate(
			zgwApi: 'catalogi',
			resource: 'zaaktypen',
			action: 'destroy',
			body: $body,
			existingObject: ['id' => 'ct-1'],
			objectService: new \stdClass(),
			mappingConfig: ['sourceRegister' => 'reg']
		);
	}//end destroy()

	/**
	 * A delete check that could not run refuses with a 503 and its own code, and the delete does not
	 * go on, also when the caller confirmed.
	 *
	 * Control: a check that ran and found no cases lets the delete go on.
	 *
	 * @return void
	 */
	public function testACheckThatCouldNotRunRefusesTheDelete(): void {
		$free = $this->destroy(verdict: ['blocked' => false, 'requiresConfirmation' => false, 'message' => '']);
		$this->assertTrue($free['valid']);
		$this->assertSame(1, $this->dispatched);

		$unverified = ['blocked' => true, 'requiresConfirmation' => false, 'unverified' => true, 'message' => 'Could not be completed.'];
		$this->assertSame(
			['valid' => false, 'status' => 503, 'detail' => 'Could not be completed.', 'code' => 'destroy_check_not_completed', 'enrichedBody' => ['_confirm' => true]],
			$this->destroy(verdict: $unverified, body: ['_confirm' => true])
		);
		$this->assertSame(1, $this->dispatched, 'a refused delete does not reach the per-register rules');
	}//end testACheckThatCouldNotRunRefusesTheDelete()

	/**
	 * Active cases stay a conflict, and closed cases ask for confirmation once.
	 *
	 * @return void
	 */
	public function testActiveAndClosedCasesKeepTheirAnswers(): void {
		$active = $this->destroy(verdict: ['blocked' => true, 'requiresConfirmation' => false, 'message' => '3 active.']);
		$closed = ['blocked' => false, 'requiresConfirmation' => true, 'message' => '2 closed.'];
		$asked  = $this->destroy(verdict: $closed);

		$this->assertSame([409, 'destroy_blocked_active_cases'], [$active['status'], $active['code']]);
		$this->assertSame([409, 'destroy_requires_confirmation'], [$asked['status'], $asked['code']]);
		$this->assertTrue($this->destroy(verdict: $closed, body: ['_confirm' => true])['valid']);
	}//end testActiveAndClosedCasesKeepTheirAnswers()
}//end class
