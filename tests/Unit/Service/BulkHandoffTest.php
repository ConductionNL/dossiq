<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Dossiq\BulkAction\LifecycleCasesAction;
use OCA\Dossiq\BulkAction\ReassignCasesAction;
use OCA\Dossiq\BulkAction\SetCaseAttributeAction;
use OCA\Dossiq\BulkAction\TransitionCasesAction;
use OCA\Dossiq\Service\Bulk\BulkJobHandoff;
use OCA\Dossiq\Service\Bulk\CaseTypeVersionGuard;
use OCA\Dossiq\Service\Bulk\MixedCaseTypeVersionsException;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What dossiq's side of a bulk act does, and what it refuses.
 *
 * The act itself is OpenRegister's. What is dossiq's, and therefore what is
 * pinned here, is four things: the register and schema the job walks, the
 * refusal of an unknown action, the refusal of a selection spanning two case
 * type versions, and that the act reaches the job at all.
 *
 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
 */
class BulkHandoffTest extends TestCase {

	/**
	 * A test double standing in for OpenRegister's BulkJobService.
	 *
	 * Declared here rather than mocked, because the real class cannot be
	 * loaded on a host without OpenRegister and a mock of a missing class is a
	 * mock of nothing.
	 *
	 * @var object|null
	 */
	private ?object $jobService = null;

	/**
	 * Settings answering dossiq's numeric register and case schema.
	 *
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settings;

	/**
	 * The selection-time version refusal.
	 *
	 * @var CaseTypeVersionGuard|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $versionGuard;

	/**
	 * Build the doubles each test starts from.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->jobService = new class {
			/**
			 * Every call this double received.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Record the hand-off and answer a previewed job.
			 *
			 * @param string               $actionId      The action.
			 * @param array<string, mixed> $parameters    The parameters.
			 * @param array<string, mixed> $selection     The selection.
			 * @param string|null          $justification The reason.
			 * @param string               $actorUid      The actor.
			 * @param int|null             $registerId    The register.
			 * @param int|null             $schemaId      The schema.
			 *
			 * @return array<string, mixed> The previewed job.
			 */
			public function create(
				string $actionId,
				array $parameters,
				array $selection,
				?string $justification,
				string $actorUid,
				?int $registerId,
				?int $schemaId,
			): array {
				$this->calls[] = [
					'actionId' => $actionId,
					'parameters' => $parameters,
					'selection' => $selection,
					'justification' => $justification,
					'actorUid' => $actorUid,
					'registerId' => $registerId,
					'schemaId' => $schemaId,
				];

				return ['id' => 7, 'state' => 'previewed', 'total' => count(($selection['ids'] ?? []))];
			}
		};

		$this->settings = $this->createMock(originalClassName: SettingsService::class);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => '3',
				'case_schema' => '11',
				default => '',
			}
		);
		$this->settings->method('getOpenRegisterClass')->willReturnCallback(
			function (string $class): ?object {
				if ($class === 'OCA\OpenRegister\Service\BulkJob\BulkJobService') {
					return $this->jobService;
				}

				return null;
			}
		);

		$this->versionGuard = $this->createMock(originalClassName: CaseTypeVersionGuard::class);
	}//end setUp()

	/**
	 * Build the subject.
	 *
	 * @return BulkJobHandoff The hand-off.
	 */
	private function handoff(): BulkJobHandoff {
		return new BulkJobHandoff(
			settingsService: $this->settings,
			versionGuard: $this->versionGuard,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end handoff()

	/**
	 * The act reaches the job, scoped to dossiq's register and case schema.
	 *
	 * The scope is the half that cannot be read off the response: a job created
	 * without one hydrates nothing, marks every member not visible, and
	 * presents that as a finished preview.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testTheActIsHandedOverScopedToTheCaseRegisterAndSchema(): void {
		$job = $this->handoff()->create(
			actionId: TransitionCasesAction::ID,
			parameters: ['transitionId' => 'to-decided'],
			selection: ['ids' => ['case-1', 'case-2']],
			justification: null,
			actorUid: 'coordinator-1',
		);

		$this->assertSame(expected: 'previewed', actual: $job['state']);
		$this->assertCount(expectedCount: 1, haystack: $this->jobService->calls);

		$call = $this->jobService->calls[0];
		$this->assertSame(expected: TransitionCasesAction::ID, actual: $call['actionId']);
		$this->assertSame(expected: 3, actual: $call['registerId']);
		$this->assertSame(expected: 11, actual: $call['schemaId']);
		$this->assertSame(expected: 'coordinator-1', actual: $call['actorUid']);
		$this->assertSame(expected: ['ids' => ['case-1', 'case-2']], actual: $call['selection']);
	}//end testTheActIsHandedOverScopedToTheCaseRegisterAndSchema()

	/**
	 * The written reason travels with the act.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testTheJustificationTravelsWithARedistribution(): void {
		$this->handoff()->create(
			actionId: ReassignCasesAction::ID,
			parameters: ['toUser' => 'handler-2', 'reason' => 'Team reorganised on 1 October'],
			selection: ['ids' => ['case-1']],
			justification: 'Team reorganised on 1 October',
			actorUid: 'coordinator-1',
		);

		$this->assertSame(expected: 'Team reorganised on 1 October', actual: $this->jobService->calls[0]['justification']);
	}//end testTheJustificationTravelsWithARedistribution()

	/**
	 * An action dossiq does not declare never reaches the job.
	 *
	 * Without this, dossiq would be a general-purpose front door onto every
	 * action any app registered, with dossiq's case policy applied to none of
	 * them.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testAnActionDossiqDoesNotDeclareIsRefused(): void {
		$this->expectException(exception: InvalidArgumentException::class);

		$this->handoff()->create(
			actionId: 'openregister:set-properties',
			parameters: [],
			selection: ['ids' => ['case-1']],
			justification: null,
			actorUid: 'coordinator-1',
		);
	}//end testAnActionDossiqDoesNotDeclareIsRefused()

	/**
	 * A selection spanning two case type versions is refused BEFORE the job is
	 * created, which is what makes the refusal earlier than the rehearsal.
	 *
	 * The assertion that matters is the second one: a refusal raised after the
	 * hand-off would still be a refusal, and would still have rehearsed an act
	 * that should never have been offered (D-4).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testAMixedVersionSelectionIsRefusedBeforeTheJobIsCreated(): void {
		$this->versionGuard->method('assertOneVersion')->willThrowException(
			new MixedCaseTypeVersionsException(
				caseTypeTitle: 'Bezwaar',
				versions: [2, 3],
				counts: ['2' => 18, '3' => 4],
			)
		);

		try {
			$this->handoff()->create(
				actionId: SetCaseAttributeAction::ID,
				parameters: ['property' => 'confidentiality', 'value' => 'openbaar'],
				selection: ['ids' => ['case-1', 'case-2']],
				justification: null,
				actorUid: 'coordinator-1',
			);
			$this->fail(message: 'The mixed-version selection was not refused');
		} catch (MixedCaseTypeVersionsException $e) {
			$this->assertSame(expected: [2, 3], actual: $e->getVersions());
			$this->assertSame(expected: 'Bezwaar', actual: $e->getCaseTypeTitle());
		}

		$this->assertSame(expected: [], actual: $this->jobService->calls, message: 'The job was created despite the refusal');
	}//end testAMixedVersionSelectionIsRefusedBeforeTheJobIsCreated()

	/**
	 * The version guard runs for the attribute write and for nothing else.
	 *
	 * A transition, a lifecycle gesture and a redistribution mean the same
	 * thing on every version of a case type, so guarding them would refuse a
	 * selection there is no reason to refuse.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testOnlyTheAttributeWriteIsVersionGuarded(): void {
		$this->versionGuard->expects($this->never())->method('assertOneVersion');

		$this->handoff()->create(
			actionId: LifecycleCasesAction::ID,
			parameters: ['gesture' => 'suspend', 'reason' => 'Awaiting documents', 'days' => 14],
			selection: ['ids' => ['case-1']],
			justification: 'Awaiting documents',
			actorUid: 'coordinator-1',
		);

		$this->assertCount(expectedCount: 1, haystack: $this->jobService->calls);
	}//end testOnlyTheAttributeWriteIsVersionGuarded()

	/**
	 * Without OpenRegister there is no job, and the caller is told so rather
	 * than being handed an empty previewed act.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/bulk-actions-report-progress/specs/case-management/spec.md
	 */
	public function testAnInstanceWithoutOpenRegisterRefusesTheAct(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturn('3');
		$settings->method('getOpenRegisterClass')->willReturn(null);

		$handoff = new BulkJobHandoff(
			settingsService: $settings,
			versionGuard: $this->versionGuard,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$this->expectException(exception: RuntimeException::class);

		$handoff->create(
			actionId: TransitionCasesAction::ID,
			parameters: ['transitionId' => 'to-decided'],
			selection: ['ids' => ['case-1']],
			justification: null,
			actorUid: 'coordinator-1',
		);
	}//end testAnInstanceWithoutOpenRegisterRefusesTheAct()
}//end class
