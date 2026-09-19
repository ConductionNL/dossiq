<?php

/**
 * Unit tests for TermijnService::rearmForDefinition().
 *
 * 🔴 THE FIXTURE PAIR OF DESIGN D-2, AND IT IS THE WHOLE POINT OF THE METHOD.
 * A rebind moves no statutory clock: the case was received on a day, and the
 * Awb term runs from the day it was received, not from the day somebody noticed
 * it had been filed under the wrong type. So the successor keeps the old
 * instance's `startDate` and takes only its DURATION from the target
 * definition, and the extensions already granted travel as days rather than as
 * an end date, because an end date carries the old duration with it.
 *
 * A 56-day term started 1 June, extended once by 14 days, rebound on 20 June to
 * an 84-day definition, therefore ends on 1 June plus 98 days: 2026-09-07. Both
 * halves are asserted, the calculated end and the current one, because a
 * successor that took the target's duration and dropped the extension passes
 * any test that only checks one of them.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Re-arming a running term against another case type's definition.
 *
 * @covers \OCA\Dossiq\Service\TermijnService
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class TermijnRearmTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The in-memory store.
	 *
	 * @var FakeTermijnStore
	 */
	private FakeTermijnStore $objects;

	/**
	 * The service under test.
	 *
	 * @var TermijnService
	 */
	private TermijnService $service;

	/**
	 * A 56-day definition on the old case type and an 84-day one on the new.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'termijn_definitie_schema' => 'deadlineDefinition',
					'termijn_instance_schema' => 'deadlineInstance',
					'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
					default => '',
				};
			},
		);

		// The one date path, injected rather than left to default. Without it
		// `startOf()` answers null and the successor silently starts today
		// instead of on the day the case was received, which is a statutory
		// date moving because a test built the service with two arguments.
		$this->service = new TermijnService(
			$settings,
			new NullLogger(),
			null,
			null,
			$this->caseDates()
		);

		$this->objects->seed('deadlineDefinition', [
			'id' => 'td-kap',
			'caseType' => 'kapvergunning',
			'legalBasis' => 'Awb 4:13',
			'standardDurationDays' => 56,
			'validFrom' => '2026-01-01',
		]);
		$this->objects->seed('deadlineDefinition', [
			'id' => 'td-omg',
			'caseType' => 'omgevingsvergunning-uitgebreid',
			'legalBasis' => 'Wabo 3.12',
			'standardDurationDays' => 84,
			'validFrom' => '2026-01-01',
		]);
	}//end setUp()

	/**
	 * Seed one running 56-day term with one 14-day extension on it.
	 *
	 * @return string The instance id.
	 */
	private function seedRunningTerm(): string {
		$instance = $this->objects->seed('deadlineInstance', [
			'id' => 'ti-1',
			'case' => 'case-1',
			'deadlineDefinition' => 'td-kap',
			'startDate' => '2026-06-01T09:00:00+00:00',
			'endDateCalculated' => '2026-07-27',
			'endDateCurrent' => '2026-08-10',
			'status' => 'lopend',
			'countExtensions' => 1,
		]);

		$this->objects->seed('termijnGebeurtenis', [
			'id' => 'tg-start',
			'deadlineInstance' => 'ti-1',
			'type' => 'start',
			'moment' => '2026-06-01T09:00:00+00:00',
			'basis' => 'Awb 4:13',
			'rationale' => 'Termijn gestart bij zaak-aanmaak',
			'daysImpact' => 56,
		]);
		$this->objects->seed('termijnGebeurtenis', [
			'id' => 'tg-verdaging',
			'deadlineInstance' => 'ti-1',
			'type' => 'verdaging',
			'moment' => '2026-06-15T09:00:00+00:00',
			'basis' => 'Awb 4:14 lid 3',
			'rationale' => 'Verdaging wegens ontbrekende gegevens',
			'daysImpact' => 14,
		]);

		return (string)$instance['id'];
	}//end seedRunningTerm()

	/**
	 * 🔴 The successor starts on 1 June and ends on 1 June plus 98 days.
	 *
	 * @return void
	 */
	public function testTheSuccessorKeepsTheStartAndCarriesTheExtension(): void {
		$this->seedRunningTerm();

		$outcome = $this->service->rearmForDefinition(
			caseId: 'case-1',
			caseTypeSlug: 'omgevingsvergunning-uitgebreid',
			reason: 'Verkeerd ingeboekt bij intake',
		);

		self::assertSame(1, $outcome['rearmed']);
		self::assertSame(0, $outcome['kept']);

		$successor = $this->successor();
		self::assertSame('td-omg', $successor['deadlineDefinition']);
		self::assertSame(
			'2026-06-01',
			(new DateTimeImmutable((string)$successor['startDate']))->format('Y-m-d'),
			'A rebind moves no statutory clock, so the successor starts the day the case was received.'
		);
		self::assertSame(
			'2026-08-24',
			$successor['endDateCalculated'],
			'The target definition supplies the duration: 1 June plus 84 days.'
		);
		self::assertSame(
			'2026-09-07',
			$successor['endDateCurrent'],
			'The 14-day verdaging already granted travels with the case: 1 June plus 98 days.'
		);
		self::assertSame(1, $successor['countExtensions']);
	}//end testTheSuccessorKeepsTheStartAndCarriesTheExtension()

	/**
	 * The old term is closed, and the trail says a rebind closed it.
	 *
	 * Recording it as "voltooid door beschikking" would put a decision in the
	 * audit trail of a case that never got one.
	 *
	 * @return void
	 */
	public function testTheOldTermIsClosedAndSaysWhy(): void {
		$instanceId = $this->seedRunningTerm();

		$this->service->rearmForDefinition(
			caseId: 'case-1',
			caseTypeSlug: 'omgevingsvergunning-uitgebreid',
			reason: 'Verkeerd ingeboekt bij intake',
		);

		$old = $this->objects->get('deadlineInstance', $instanceId);
		self::assertSame('completed', $old['status']);

		$closing = [];
		foreach (($this->objects->store['termijnGebeurtenis'] ?? []) as $event) {
			if ((string)$event['deadlineInstance'] === $instanceId && (string)$event['type'] === 'voltooi') {
				$closing = $event;
			}
		}

		self::assertNotSame([], $closing, 'Closing the old term must be recorded.');
		self::assertStringContainsString('herbinding', (string)$closing['rationale']);
		self::assertStringContainsString('Verkeerd ingeboekt bij intake', (string)$closing['rationale']);
	}//end testTheOldTermIsClosedAndSaysWhy()

	/**
	 * The extension is re-recorded on the successor, not only counted.
	 *
	 * A number with no event behind it is an end date nobody can account for.
	 *
	 * @return void
	 */
	public function testTheCarriedExtensionIsRecordedOnTheSuccessor(): void {
		$this->seedRunningTerm();

		$this->service->rearmForDefinition(
			caseId: 'case-1',
			caseTypeSlug: 'omgevingsvergunning-uitgebreid',
			reason: 'Verkeerd ingeboekt bij intake',
		);

		$successorId = (string)$this->successor()['id'];

		$carried = [];
		foreach (($this->objects->store['termijnGebeurtenis'] ?? []) as $event) {
			if ((string)$event['deadlineInstance'] === $successorId && (string)$event['type'] === 'verdaging') {
				$carried[] = $event;
			}
		}

		self::assertCount(1, $carried);
		self::assertSame(14, $carried[0]['daysImpact']);
		self::assertSame('Awb 4:14 lid 3', $carried[0]['basis']);
	}//end testTheCarriedExtensionIsRecordedOnTheSuccessor()

	/**
	 * 🔴 A target with no term definition leaves the running clock alone.
	 *
	 * Completing a statutory term that has no successor is how a case silently
	 * stops being watched, so the old instance stays and the answer says so.
	 *
	 * @return void
	 */
	public function testATargetWithoutADefinitionKeepsTheRunningTerm(): void {
		$instanceId = $this->seedRunningTerm();

		$outcome = $this->service->rearmForDefinition(
			caseId: 'case-1',
			caseTypeSlug: 'sloopmelding',
			reason: 'Verkeerd ingeboekt bij intake',
		);

		self::assertSame(0, $outcome['rearmed']);
		self::assertSame(1, $outcome['kept']);
		self::assertNotSame('', $outcome['note']);
		self::assertSame('lopend', $this->objects->get('deadlineInstance', $instanceId)['status']);
	}//end testATargetWithoutADefinitionKeepsTheRunningTerm()

	/**
	 * A case with no running term is a clean zero rather than a failure.
	 *
	 * @return void
	 */
	public function testACaseWithNoRunningTermIsANoOp(): void {
		$outcome = $this->service->rearmForDefinition(
			caseId: 'case-1',
			caseTypeSlug: 'omgevingsvergunning-uitgebreid',
			reason: 'Verkeerd ingeboekt bij intake',
		);

		self::assertSame(['rearmed' => 0, 'kept' => 0, 'note' => ''], $outcome);
	}//end testACaseWithNoRunningTermIsANoOp()

	/**
	 * The instance the re-arm created, which is the one that is not `ti-1`.
	 *
	 * @return array<string, mixed> The successor.
	 */
	private function successor(): array {
		foreach (($this->objects->store['deadlineInstance'] ?? []) as $id => $row) {
			if ((string)$id !== 'ti-1') {
				return $row;
			}
		}

		self::fail('The re-arm created no successor instance.');
	}//end successor()
}//end class
