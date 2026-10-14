<?php

/**
 * Unit tests for QueueCaseFacts.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\Queue\QueueCaseFacts;
use OCA\Dossiq\Service\Queue\UrgencyProfile;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The deadline, last activity and urgency profile of a case, asked of the class itself.
 *
 * @covers \OCA\Dossiq\Service\Queue\QueueCaseFacts
 * @uses \OCA\Dossiq\Service\Queue\UrgencyProfile
 * @uses \OCA\Dossiq\Service\Lifecycle\CaseJournal
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class QueueCaseFactsTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The warnings logged, each with its context.
	 *
	 * @var array<int, array{0: string, 1: array}>
	 */
	private array $warnings = [];

	/**
	 * The facts, over the given schema configuration.
	 *
	 * @param array<string, string> $config Schema per settings key.
	 *
	 * @return QueueCaseFacts
	 */
	private function facts(array $config = ['termijn_instance_schema' => 'term', 'case_type_schema' => 'caseType']): QueueCaseFacts {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new QueueCaseFacts(
			settingsService: $settings,
			journal: new CaseJournal(userSession: $this->createMock(IUserSession::class)),
			dates: $this->caseDates(),
			logger: $logger
		);
	}//end facts()

	/**
	 * A store answering one search and one find, or failing either.
	 *
	 * @param array|\Throwable $rows What a search answers.
	 * @param array|\Throwable|null $found What a find answers.
	 *
	 * @return object
	 */
	private function store(array|\Throwable $rows = [], array|\Throwable|null $found = null): object {
		return new class($rows, $found) {
			/**
			 * Every call, as [method, schema, filters or id].
			 *
			 * @var array<int, array>
			 */
			public array $calls = [];

			public function __construct(
				private array|\Throwable $rows,
				private array|\Throwable|null $found,
			) {
			}//end __construct()

			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->calls[] = ['search', $schema, $filters];
				if ($this->rows instanceof \Throwable) {
					throw $this->rows;
				}

				return $this->rows;
			}//end searchObjectsBySlug()

			public function find(string $id, int|string|null $register = null, int|string|null $schema = null): ?array {
				$this->calls[] = ['find', $schema, $id];
				if ($this->found instanceof \Throwable) {
					throw $this->found;
				}

				return $this->found;
			}//end find()
		};
	}//end store()

	/**
	 * The nearest end among the open terms of the case is its deadline.
	 *
	 * @return void
	 */
	public function testTheNearestOpenTermIsTheDeadline(): void {
		$store = $this->store(
			rows: [
				['status' => 'lopend', 'endDateCurrent' => '2026-08-20'],
				['status' => 'paused', 'endDateCurrent' => '2026-08-05'],
				['status' => 'exceeded', 'endDateCurrent' => '2026-08-06'],
				['status' => 'verlengd', 'endDateCurrent' => '2026-08-07'],
				['status' => 'afgerond', 'endDateCurrent' => '2026-07-01'],
				['status' => 'lopend', 'endDateCurrent' => ''],
				['endDateCurrent' => '2026-07-02'],
			]
		);

		$this->assertSame('2026-08-05', $this->facts()->resolveCaseDeadline(objectService: $store, register: 'reg', caseId: 'c1', fallback: '2026-12-31'));
		$this->assertSame([['search', 'term', ['case' => 'c1']]], $store->calls);
	}//end testTheNearestOpenTermIsTheDeadline()

	/**
	 * No open term, no term schema, no case id or a failed read: the case's own deadline, or none.
	 *
	 * @return void
	 */
	public function testTheCasesOwnDeadlineIsTheFallback(): void {
		$closed = $this->store(rows: [['status' => 'afgerond', 'endDateCurrent' => '2026-07-01']]);
		$failed = $this->store(rows: new \RuntimeException('down'));
		$unread = $this->store(rows: [['status' => 'lopend', 'endDateCurrent' => '2026-07-01']]);

		$this->assertSame('2026-12-31', $this->facts()->resolveCaseDeadline($closed, 'reg', 'c1', '2026-12-31'));
		$this->assertNull($this->facts()->resolveCaseDeadline($closed, 'reg', 'c1', ''));
		$this->assertSame('2026-12-31', $this->facts()->resolveCaseDeadline($failed, 'reg', 'c1', '2026-12-31'));
		$this->assertSame('2026-12-31', $this->facts(config: [])->resolveCaseDeadline($unread, 'reg', 'c1', '2026-12-31'));
		$this->assertSame('2026-12-31', $this->facts()->resolveCaseDeadline($unread, 'reg', '', '2026-12-31'));
		$this->assertSame([], $unread->calls);
		$this->assertSame([], $this->warnings);
	}//end testTheCasesOwnDeadlineIsTheFallback()

	/**
	 * The last activity is the latest of the last save and the journal, as a day; without either
	 * it is the start date, and without that there is none.
	 *
	 * @return void
	 */
	public function testTheLastActivityIsTheLatestMoment(): void {
		$facts   = $this->facts();
		$journal = [['at' => '2026-07-02T09:00:00+00:00'], ['at' => '2026-07-08T09:00:00+00:00'], ['at' => ''], ['at' => 7], ['note' => 'no moment'], ['at' => 'geen datum']];

		$this->assertSame(
			'2026-07-08',
			$facts->lastActivityOf(case: ['@self' => ['updated' => '2026-07-05T09:00:00+00:00'], CaseJournal::FIELD => $journal, 'startDate' => '2026-01-01'])
		);
		$this->assertSame(
			'2026-07-10',
			$facts->lastActivityOf(case: ['@self' => ['updated' => '2026-07-10T09:00:00+00:00'], CaseJournal::FIELD => json_encode($journal)])
		);
		$this->assertSame('2026-01-01', $facts->lastActivityOf(case: ['startDate' => '2026-01-01', CaseJournal::FIELD => 'geen json']));
		$this->assertNull($facts->lastActivityOf(case: ['startDate' => '']));
		$this->assertNull($facts->lastActivityOf(case: []));
	}//end testTheLastActivityIsTheLatestMoment()

	/**
	 * A case names its type by id, or as an object carrying an id or a uuid.
	 *
	 * @return void
	 */
	public function testTheCaseTypeIdIsReadFromEitherShape(): void {
		$facts = $this->facts();

		$this->assertSame('ct-1', $facts->caseTypeIdOf(case: ['caseType' => 'ct-1']));
		$this->assertSame('ct-2', $facts->caseTypeIdOf(case: ['caseType' => ['id' => 'ct-2', 'uuid' => 'other']]));
		$this->assertSame('ct-3', $facts->caseTypeIdOf(case: ['caseType' => ['uuid' => 'ct-3']]));
		$this->assertSame('', $facts->caseTypeIdOf(case: ['caseType' => []]));
		$this->assertSame('', $facts->caseTypeIdOf(case: ['caseType' => ['id' => 7]]));
		$this->assertSame('', $facts->caseTypeIdOf(case: ['caseType' => 7]));
		$this->assertSame('', $facts->caseTypeIdOf(case: []));
	}//end testTheCaseTypeIdIsReadFromEitherShape()

	/**
	 * The case type's own thresholds replace the instance ones where it sets them.
	 *
	 * @return void
	 */
	public function testTheCaseTypeSetsItsOwnThresholds(): void {
		$instance = new UrgencyProfile(criticalDays: 3, warningDays: 7, priorityWeight: 10, idleWeight: 0.5);
		$store    = $this->store(found: ['queueCriticalDays' => 10, 'queueWarningDays' => null]);

		$profile = $this->facts()->profileForCaseType(objectService: $store, register: 'reg', caseTypeId: 'ct-1', profile: $instance);

		$this->assertSame(10, $profile->criticalDays);
		$this->assertSame($instance->priorityWeight, $profile->priorityWeight);
		$this->assertSame($instance->idleWeight, $profile->idleWeight);
		$this->assertSame([['find', 'caseType', 'ct-1']], $store->calls);
	}//end testTheCaseTypeSetsItsOwnThresholds()

	/**
	 * No type, no schema, a type that is gone or one that cannot be read: the instance profile,
	 * and only the failed read is said in the log.
	 *
	 * @return void
	 */
	public function testAnUnreadableCaseTypeScoresWithTheInstanceProfile(): void {
		$instance = new UrgencyProfile();
		$found    = $this->store(found: ['queueCriticalDays' => 10]);

		$this->assertSame($instance, $this->facts()->profileForCaseType($found, 'reg', '', $instance));
		$this->assertSame($instance, $this->facts(config: [])->profileForCaseType($found, 'reg', 'ct-1', $instance));
		$this->assertSame([], $found->calls);
		$this->assertSame($instance, $this->facts()->profileForCaseType($this->store(found: null), 'reg', 'ct-1', $instance));
		$this->assertSame([], $this->warnings);

		$this->assertSame($instance, $this->facts()->profileForCaseType($this->store(found: new \RuntimeException('down')), 'reg', 'ct-1', $instance));
		$this->assertSame(
			[['WorkQueue: case type read failed, scoring with the instance thresholds', ['caseType' => 'ct-1', 'error' => 'down']]],
			$this->warnings
		);
	}//end testAnUnreadableCaseTypeScoresWithTheInstanceProfile()
}//end class
