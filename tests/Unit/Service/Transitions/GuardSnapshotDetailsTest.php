<?php

/**
 * The shape of the guard snapshot that gets persisted on the status record.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\MandaatValidationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Transitions\ChecklistGuard;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\MandaatGuard;
use OCA\Dossiq\Service\Transitions\RequiredDocumentGuard;
use OCA\Dossiq\Service\Transitions\RequiredFieldGuard;
use OCA\Dossiq\Service\Transitions\RoleGuard;
use OCA\Dossiq\Service\Transitions\StatusChecklistGuard;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A guard that reports nothing must snapshot `details` as null, not `{}`.
 *
 * `evaluateAll()` output is persisted verbatim as `statusRecord.evaluatedGuards`,
 * whose `details` is declared `type: object` in `dossiq_register.json`.
 * OpenRegister rejects an empty object and says exactly what it wants:
 *
 *     Property 'evaluatedGuards.0.details' expects object but got empty ({}).
 *     For non-required object properties, set this to null to clear the field.
 *
 * `GuardResult::$details` defaults to `[]` and a guard that simply passes has
 * nothing to report, so the empty case is the COMMON one. It went unnoticed
 * until the status checklist guard began being appended to every transition:
 * before that, a transition declaring no guards produced no snapshot entries
 * at all, so there was nothing to reject. Afterwards every transition had at
 * least entry 0, `StatusTransitionController::execute()` caught the throwable
 * and answered 500, and the UI showed a transition dialog that never closed.
 *
 * The @uses set matches GuardDialectTest's, because this test builds the same
 * registry with the same real evaluators: constructing one executes its
 * constructor, and phpunit.xml sets beStrictAboutCoverageMetadata with
 * failOnRisky, so an unlisted class exits 1 with zero failures reported.
 *
 * @covers \OCA\Dossiq\Service\Transitions\GuardRegistry
 * @uses \OCA\Dossiq\Service\Transitions\ChecklistGuard
 * @uses \OCA\Dossiq\Service\Transitions\GuardResult
 * @uses \OCA\Dossiq\Service\Transitions\MandaatGuard
 * @uses \OCA\Dossiq\Service\Transitions\RequiredDocumentGuard
 * @uses \OCA\Dossiq\Service\Transitions\RequiredFieldGuard
 * @uses \OCA\Dossiq\Service\Transitions\RoleGuard
 */
class GuardSnapshotDetailsTest extends TestCase {
	/**
	 * A guard with nothing to report snapshots null, never an empty object.
	 *
	 * @return void
	 */
	public function testAGuardWithNothingToReportSnapshotsNullDetails(): void {
		// `checklist` is the guard that produces the empty case in
		// production: every one of its early returns, and its PASSING verdict,
		// construct a GuardResult without details.
		$results = $this->registry()->evaluateAll(
			guards: [['type' => 'checklist']],
			case: ['id' => 'case-1'],
			userId: 'admin',
		);

		self::assertCount(1, $results);
		self::assertNull(
			$results[0]['details'],
			'An empty details array is persisted as {} and OpenRegister refuses it.',
		);
	}//end testAGuardWithNothingToReportSnapshotsNullDetails()

	/**
	 * A guard that DOES report details keeps them untouched.
	 *
	 * The control. Nulling the empty case must not null the populated one, or
	 * the reason a transition was refused would stop being recorded.
	 *
	 * @return void
	 */
	public function testAGuardWithDetailsKeepsThem(): void {
		$results = $this->registry()->evaluateAll(
			guards: [['type' => 'no_such_guard_type']],
			case: [],
			userId: 'admin',
		);

		self::assertCount(1, $results);
		self::assertFalse($results[0]['passed']);
		self::assertSame(['unknown' => true], $results[0]['details']);
	}//end testAGuardWithDetailsKeepsThem()

	/**
	 * Every snapshot entry is safe to persist, whatever the guard decided.
	 *
	 * This is the invariant the schema actually cares about, stated once so a
	 * new guard cannot reintroduce the defect by defaulting `details` again.
	 *
	 * @return void
	 */
	public function testNoSnapshotEntryCarriesAnEmptyDetailsObject(): void {
		$results = $this->registry()->evaluateAll(
			guards: [
				['type' => 'checklist'],
				['type' => 'requiredField', 'field' => 'title'],
				['type' => 'requiredField', 'field' => 'absent'],
				['type' => 'no_such_guard_type'],
			],
			case: ['title' => 'Present'],
			userId: 'admin',
		);

		self::assertCount(4, $results);
		foreach ($results as $index => $entry) {
			self::assertNotSame(
				[],
				$entry['details'],
				sprintf('evaluatedGuards.%d.details is an empty object', $index),
			);
		}
	}//end testNoSnapshotEntryCarriesAnEmptyDetailsObject()

	/**
	 * A registry wired with the real evaluators.
	 *
	 * @return GuardRegistry
	 */
	private function registry(): GuardRegistry {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($this->createMock(IUser::class));

		return new GuardRegistry(
			new ChecklistGuard($settings, new NullLogger()),
			new RequiredFieldGuard(),
			new RequiredDocumentGuard(),
			new RoleGuard($this->createMock(IGroupManager::class), $userManager, new NullLogger()),
			new MandaatGuard($this->createMock(MandaatValidationService::class)),
			$this->createMock(StatusChecklistGuard::class),
			new NullLogger(),
		);
	}//end registry()
}//end class
