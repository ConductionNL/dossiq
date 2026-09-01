<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Parafeer
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Parafeer;

use OCA\Dossiq\Service\Parafeer\ParaafFlowLinkage;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Minimal ObjectService shape for the linkage tests.
 *
 * Copied from OpenRegister's ObjectService, not from the call site: a double
 * written from the caller encodes the caller's assumptions and passes on its
 * bugs.
 */
interface ParaafLinkageObjectServiceStub {

	/**
	 * @param string              $registerSlug Register slug.
	 * @param string              $schemaSlug   Schema slug.
	 * @param array<string,mixed> $filters      Field filters.
	 *
	 * @return array<int,mixed>|int The rows.
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array|int;

	/**
	 * @param array<string,mixed> $object   The object.
	 * @param string              $register The register.
	 * @param string              $schema   The schema.
	 *
	 * @return array<string,mixed> The stored object.
	 */
	public function saveObject(array $object, string $register, string $schema): array;

}//end interface

/**
 * Unit tests for ParaafFlowLinkage.
 *
 * @covers \OCA\Dossiq\Service\Parafeer\ParaafFlowLinkage
 */
class ParaafFlowLinkageTest extends TestCase {

	/**
	 * Objects the fake object service was asked to save.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * Build the linkage over a fake object service.
	 *
	 * @param array<int, mixed>|string $rows       Rows the search returns, or 'throw'.
	 * @param boolean                  $available  Whether OpenRegister is reachable.
	 * @param string                   $configured What getConfigValue answers.
	 * @param boolean                  $saveThrows Whether saveObject fails.
	 *
	 * @return ParaafFlowLinkage The linkage.
	 */
	private function linkage(
		array|string $rows = [],
		bool $available = true,
		string $configured = 'test-value',
		bool $saveThrows = false,
	): ParaafFlowLinkage {
		$objectService = null;

		if ($available === true) {
			$saved = &$this->saved;
			$objectService = $this->createMock(ParaafLinkageObjectServiceStub::class);

			if (is_string($rows) === true) {
				$objectService->method('searchObjectsBySlug')
					->willThrowException(new RuntimeException('the object store is unreachable'));
			} else {
				$objectService->method('searchObjectsBySlug')->willReturn($rows);
			}

			if ($saveThrows === true) {
				$objectService->method('saveObject')
					->willThrowException(new RuntimeException('validation refused the write'));
			} else {
				$objectService->method('saveObject')->willReturnCallback(
					static function (array $object) use (&$saved): array {
						$saved[] = $object;

						return $object;
					}
				);
			}
		}

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturn($configured);

		return new ParaafFlowLinkage($settings, $this->createMock(LoggerInterface::class));

	}//end linkage()

	/**
	 * 🔴 The voorstel's run id is what decides which path parafering takes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testItReportsTheRunDrivingTheVoorstel(): void {
		$linkage = $this->linkage([['id' => 'voorstel-1', 'flowRunId' => 'run-1']]);

		$this->assertSame('run-1', $linkage->runDriving(proposalId: 'voorstel-1'));

	}//end testItReportsTheRunDrivingTheVoorstel()

	/**
	 * 🔴 A voorstel on the route path reports no run.
	 *
	 * This is every voorstel today, and the answer that keeps the old path
	 * untouched by the new one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAVoorstelOnTheRoutePathReportsNoRun(): void {
		$this->assertSame(
			'',
			$this->linkage([['id' => 'voorstel-1']])->runDriving(proposalId: 'voorstel-1')
		);

	}//end testAVoorstelOnTheRoutePathReportsNoRun()

	/**
	 * A voorstel that cannot be found reports no run.
	 *
	 * @return void
	 */
	public function testAnUnknownVoorstelReportsNoRun(): void {
		$this->assertSame('', $this->linkage([])->runDriving(proposalId: 'voorstel-1'));

	}//end testAnUnknownVoorstelReportsNoRun()

	/**
	 * An unreachable object store reports no run rather than throwing.
	 *
	 * The listener runs after the paraaf is already saved. Throwing here would
	 * turn a lookup failure into a failed write the user already completed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAnUnreachableStoreReportsNoRun(): void {
		$this->assertSame('', $this->linkage('throw')->runDriving(proposalId: 'voorstel-1'));

	}//end testAnUnreachableStoreReportsNoRun()

	/**
	 * Without OpenRegister there is no run to report.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterThereIsNoRun(): void {
		$this->assertSame(
			'',
			$this->linkage([], available: false)->runDriving(proposalId: 'voorstel-1')
		);

	}//end testWithoutOpenRegisterThereIsNoRun()

	/**
	 * An unconfigured register or schema reports no run.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterReportsNoRun(): void {
		$this->assertSame(
			'',
			$this->linkage([['id' => 'voorstel-1', 'flowRunId' => 'run-1']], configured: '')
				->runDriving(proposalId: 'voorstel-1')
		);

	}//end testAnUnconfiguredRegisterReportsNoRun()

	/**
	 * 🔴 Stamping records BOTH the run and the node.
	 *
	 * A run holds one awaiting slot per node and cannot say which of them a
	 * signal answers, so a paraaf naming only the run could not resume it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testStampingRecordsTheRunAndTheNode(): void {
		$linkage = $this->linkage();

		$linkage->stamp(
			paraaf: ['id' => 'paraaf-1', 'proposal' => 'voorstel-1', 'action' => 'parafered'],
			runUuid: 'run-1',
			nodeId: 'step-1',
		);

		$this->assertCount(1, $this->saved);
		$this->assertSame('run-1', $this->saved[0]['flowRun']);
		$this->assertSame('step-1', $this->saved[0]['flowNode']);
		// The paraaf's own fields survive the stamp.
		$this->assertSame('parafered', $this->saved[0]['action']);

	}//end testStampingRecordsTheRunAndTheNode()

	/**
	 * 🔴 A failed stamp is reported, never raised.
	 *
	 * The signal is what advances the run. Losing the stamp costs
	 * traceability, not correctness, and throwing here would leave the run
	 * suspended for a paraaf that was already given.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAFailedStampIsReportedNotRaised(): void {
		$linkage = $this->linkage(saveThrows: true);

		$linkage->stamp(paraaf: ['id' => 'paraaf-1'], runUuid: 'run-1', nodeId: 'step-1');

		$this->assertSame([], $this->saved);

	}//end testAFailedStampIsReportedNotRaised()

	/**
	 * Without OpenRegister there is nothing to stamp.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterThereIsNothingToStamp(): void {
		$this->linkage([], available: false)
			->stamp(paraaf: ['id' => 'paraaf-1'], runUuid: 'run-1', nodeId: 'step-1');

		$this->assertSame([], $this->saved);

	}//end testWithoutOpenRegisterThereIsNothingToStamp()

	/**
	 * An unconfigured schema stamps nothing.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredSchemaStampsNothing(): void {
		$this->linkage([], configured: '')
			->stamp(paraaf: ['id' => 'paraaf-1'], runUuid: 'run-1', nodeId: 'step-1');

		$this->assertSame([], $this->saved);

	}//end testAnUnconfiguredSchemaStampsNothing()

	/**
	 * 🔴 setStatus moves the voorstel and keeps its other fields.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testSetStatusMovesTheVoorstel(): void {
		$linkage = $this->linkage([['id' => 'voorstel-1', 'subject' => 'Een voorstel', 'status' => 'in_parafering']]);

		$this->assertTrue($linkage->setStatus(proposalId: 'voorstel-1', status: 'geaccordeerd'));
		$this->assertCount(1, $this->saved);
		$this->assertSame('geaccordeerd', $this->saved[0]['status']);
		$this->assertSame('Een voorstel', $this->saved[0]['subject']);

	}//end testSetStatusMovesTheVoorstel()

	/**
	 * 🔴 A status the proposal schema does not declare is refused, loudly.
	 *
	 * `proposal.status` is a closed enum and OpenRegister runs hard validation
	 * by default, so an undeclared value fails the save far from the node that
	 * chose it. dossiq#1609 is what that looks like unnoticed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testAnUndeclaredStatusIsRefused(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('not a voorstel status');

		$this->linkage()->setStatus(proposalId: 'voorstel-1', status: 'gereed_voor_agendering');

	}//end testAnUndeclaredStatusIsRefused()

	/**
	 * 🔴 Every status it accepts is one the schema declares.
	 *
	 * The list lives in PHP and the enum lives in the register JSON, edited by
	 * different hands. Nothing else compares them, and a drift either way is
	 * silent: a status dropped from the list stops being writable, and one
	 * dropped from the schema starts failing on save.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function testItsStatusListMatchesTheSchema(): void {
		$register = json_decode(
			file_get_contents(__DIR__ . '/../../../../lib/Settings/dossiq_register.json'),
			true
		);
		$allowed = $register['components']['schemas']['proposal']['properties']['status']['enum'];

		$reflected = new \ReflectionClass(ParaafFlowLinkage::class);
		$declared = $reflected->getConstant('VOORSTEL_STATUSES');

		$this->assertSame(
			[],
			array_diff($declared, $allowed),
			'the linkage accepts a status the proposal schema does not declare'
		);
		$this->assertSame(
			[],
			array_diff($allowed, $declared),
			'the proposal schema declares a status the linkage would refuse'
		);

	}//end testItsStatusListMatchesTheSchema()

	/**
	 * Without OpenRegister nothing is moved, and nothing throws.
	 *
	 * @return void
	 */
	public function testSetStatusWithoutOpenRegisterMovesNothing(): void {
		$this->assertFalse(
			$this->linkage([], available: false)->setStatus(proposalId: 'voorstel-1', status: 'geaccordeerd')
		);

	}//end testSetStatusWithoutOpenRegisterMovesNothing()

	/**
	 * A voorstel that cannot be found is not moved.
	 *
	 * @return void
	 */
	public function testSetStatusOnAnUnknownVoorstelMovesNothing(): void {
		$this->assertFalse($this->linkage([])->setStatus(proposalId: 'voorstel-1', status: 'geaccordeerd'));
		$this->assertSame([], $this->saved);

	}//end testSetStatusOnAnUnknownVoorstelMovesNothing()

	/**
	 * A failed save is reported, not raised.
	 *
	 * @return void
	 */
	public function testSetStatusReportsAFailedSave(): void {
		$linkage = $this->linkage([['id' => 'voorstel-1']], saveThrows: true);

		$this->assertFalse($linkage->setStatus(proposalId: 'voorstel-1', status: 'geaccordeerd'));

	}//end testSetStatusReportsAFailedSave()

}//end class
