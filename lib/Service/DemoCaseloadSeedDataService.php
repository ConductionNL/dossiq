<?php

/**
 * Dossiq Demo Caseload Seed Data Service
 *
 * Seeds a demonstrable caseload: cases spread across the shipped case types and
 * the tasks hanging off them, positioned so every dashboard widget has rows.
 *
 * 🔴 DATES IN THE SEED FILE ARE OFFSETS, NOT DATES. A demo dataset with absolute
 * dates is correct on the day it is written and wrong every day after, which is
 * how a "the dashboard is empty again" report is really a stale fixture. Every
 * date here is resolved against the moment the seed runs.
 *
 * 🔴 A CASE'S DEADLINE CANNOT BE WRITTEN, ONLY CAUSED. `case.deadline` is a
 * materialised OpenRegister calculation (`startDate` plus the case type's
 * `processingDeadline`), so a deadline written directly is overwritten on save.
 * To place a case in the overdue bucket this service backdates `startDate` by
 * the case type's own processing deadline. {@see resolveStartDate()}.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/dossiq-app-scaffold/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Seeds the demo caseload described by lib/Settings/demo_caseload_seed_data.json.
 *
 * @spec openspec/specs/dossiq-app-scaffold/spec.md
 */
class DemoCaseloadSeedDataService {
	/**
	 * The shipped dataset, relative to this file.
	 *
	 * @var string
	 */
	private const SEED_FILE = '/../Settings/demo_caseload_seed_data.json';

	/**
	 * Constructor.
	 *
	 * @param DemoCaseloadGateway $gateway OpenRegister access.
	 * @param EngineTaskGateway $engineTask The engine's task writer.
	 * @param IUserSession $userSession The identity the seed command set.
	 * @param LoggerInterface $logger The logger interface.
	 * @param LanguageMapText $text Reads a translatable status name as text.
	 *
	 * @return void
	 */
	public function __construct(
		private DemoCaseloadGateway $gateway,
		private EngineTaskGateway $engineTask,
		private IUserSession $userSession,
		private LoggerInterface $logger,
		private LanguageMapText $text = new LanguageMapText(),
	) {
	}//end __construct()

	/**
	 * Seed the demo caseload.
	 *
	 * Idempotent by case title: a case whose title is already present is left
	 * alone, and its tasks are left alone with it.
	 *
	 * 🔴 THROWS RATHER THAN RETURNING A QUIET FAILURE. The only caller is an
	 * operator who just asked for this, so "nothing happened" must never be
	 * presentable as success.
	 *
	 * @param DateTimeImmutable|null $now The clock, injectable for tests.
	 *
	 * @return array{cases: integer, tasks: integer, skipped: integer} What was created.
	 *
	 * @throws RuntimeException When the dataset, OpenRegister or the configuration is missing.
	 *
	 * @spec openspec/specs/dossiq-app-scaffold/spec.md
	 */
	public function seed(?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable('today'));
		$seed = $this->readSeedFile();
		$objectService = $this->gateway->objectService();

		return $this->gateway->asSystem(
			objectService: $objectService,
			operation: fn (): array => $this->seedWith(
				seed: $seed,
				run: new DemoSeedRun(objectService: $objectService, now: $now)
			),
		);
	}//end seed()

	/**
	 * Seed the demo caseload through one ObjectService, inside the system-operation scope.
	 *
	 * The seed creates cases in later lifecycle statuses, which OpenRegister
	 * allows on create only to the system.
	 *
	 * @param array       $seed The dataset.
	 * @param DemoSeedRun $run  Where the seed writes, and its clock.
	 *
	 * @return array{cases: integer, tasks: integer, skipped: integer} What was created.
	 */
	private function seedWith(array $seed, DemoSeedRun $run): array {
		$this->lookUp(run: $run);

		foreach (($seed['cases'] ?? []) as $caseSeed) {
			$this->seedCase(run: $run, caseSeed: $caseSeed);
		}

		$this->logger->info('Dossiq: Demo caseload seeded', $run->summary);

		return $run->summary;
	}//end seedWith()

	/**
	 * Read the register and schema ids, the case types and the status types once.
	 *
	 * @param DemoSeedRun $run The seed.
	 *
	 * @return void
	 */
	private function lookUp(DemoSeedRun $run): void {
		$run->ids = $this->gateway->schemaIds();
		$run->caseTypes = $this->caseTypesByIdentifier(objectService: $run->objectService, ids: $run->ids);
		$run->statuses = $this->statusTypesByCaseTypeAndName(objectService: $run->objectService, ids: $run->ids);
	}//end lookUp()

	/**
	 * Seed one case and its tasks, unless it has no title or is already present.
	 *
	 * @param DemoSeedRun $run      The seed.
	 * @param array       $caseSeed The case seed entry.
	 *
	 * @return void
	 */
	private function seedCase(DemoSeedRun $run, array $caseSeed): void {
		$title = (string)($caseSeed['title'] ?? '');
		if ($title === '') {
			return;
		}

		if ($this->isPresent(run: $run, title: $title) === true) {
			$run->summary['skipped']++;
			return;
		}

		$this->createWithTasks(run: $run, caseSeed: $caseSeed);
	}//end seedCase()

	/**
	 * Create one case and then its tasks, counting both.
	 *
	 * @param DemoSeedRun $run      The seed.
	 * @param array       $caseSeed The case seed entry.
	 *
	 * @return void
	 */
	private function createWithTasks(DemoSeedRun $run, array $caseSeed): void {
		$caseId = $this->createCase(run: $run, caseSeed: $caseSeed);
		if ($caseId === '') {
			return;
		}

		$run->summary['cases']++;
		$run->summary['tasks'] += $this->createTasks(
			caseId: $caseId,
			tasks: (array)($caseSeed['tasks'] ?? []),
			now: $run->now
		);
	}//end createWithTasks()

	/**
	 * Whether the register already holds a case with this title.
	 *
	 * @param DemoSeedRun $run   The seed.
	 * @param string      $title The case title.
	 *
	 * @return bool
	 */
	private function isPresent(DemoSeedRun $run, string $title): bool {
		return $this->gateway->exists(
			objectService: $run->objectService,
			registerId: $run->ids['register'],
			schemaId: $run->ids['case'],
			filters: ['title' => $title]
		) === true;
	}//end isPresent()

	/**
	 * Create one case from its seed entry.
	 *
	 * @param DemoSeedRun $run      The seed.
	 * @param array       $caseSeed The case seed entry.
	 *
	 * @return string The created case id, or '' when it could not be created.
	 */
	private function createCase(DemoSeedRun $run, array $caseSeed): string {
		$identifier = (string)($caseSeed['caseType'] ?? '');
		if (isset($run->caseTypes[$identifier]) === false) {
			$this->logger->warning(
				'Dossiq: Demo seed skipped a case whose case type is not installed',
				['case' => ($caseSeed['title'] ?? ''), 'caseType' => $identifier]
			);
			return '';
		}

		return $this->gateway->create(
			objectService: $run->objectService,
			registerId: $run->ids['register'],
			schemaId: $run->ids['case'],
			data: $this->casePayload(run: $run, caseSeed: $caseSeed)
		);
	}//end createCase()

	/**
	 * Build the payload for one case whose case type is installed.
	 *
	 * @param DemoSeedRun $run      The seed.
	 * @param array       $caseSeed The case seed entry.
	 *
	 * @return array The case payload.
	 */
	private function casePayload(DemoSeedRun $run, array $caseSeed): array {
		$caseType = $run->caseTypes[(string)($caseSeed['caseType'] ?? '')];
		$startDate = $this->resolveStartDate(
			caseSeed: $caseSeed,
			processingDeadline: (string)($caseType['processingDeadline'] ?? ''),
			now: $run->now
		);

		$data = $this->describedCase(caseSeed: $caseSeed, caseTypeId: (string)$caseType['id']);
		$data['startDate'] = $startDate->format('Y-m-d');
		$data += $this->priorityPair(caseSeed: $caseSeed);

		return $data + $this->lifecycleFields(run: $run, caseSeed: $caseSeed);
	}//end casePayload()

	/**
	 * The fields that describe a case: what it is, who has it, how it came in.
	 *
	 * @param array  $caseSeed   The case seed entry.
	 * @param string $caseTypeId The resolved case type id.
	 *
	 * @return array<string, string>
	 */
	private function describedCase(array $caseSeed, string $caseTypeId): array {
		return [
			'title' => (string)($caseSeed['title'] ?? ''),
			'description' => (string)($caseSeed['description'] ?? ''),
			'caseType' => $caseTypeId,
			'assignee' => (string)($caseSeed['assignee'] ?? ''),
			'intakeChannel' => (string)($caseSeed['intakeChannel'] ?? 'manual'),
			'confidentiality' => (string)($caseSeed['confidentiality'] ?? 'openbaar'),
		];
	}//end describedCase()

	/**
	 * The impact and urgency behind the priority a case seed names.
	 *
	 * @param array $caseSeed The case seed entry.
	 *
	 * @return array{impact: string, urgency: string}
	 */
	private function priorityPair(array $caseSeed): array {
		// The seed names a priority because that is what a reader of the demo
		// caseload sees, but a priority cannot be written directly any more:
		// the derivation recomputes it on every save from impact and urgency,
		// so a seeded `urgent` would arrive as the instance default and the
		// ten demo cases would all read the same. Writing the pair behind the
		// priority puts it back through the matrix and gets the same answer.
		$pair = (CasePriorityService::PRIORITY_SEED_PAIRS[(string)($caseSeed['priority'] ?? 'normal')]
			?? CasePriorityService::PRIORITY_SEED_PAIRS['normal']);

		return ['impact' => $pair['impact'], 'urgency' => $pair['urgency']];
	}//end priorityPair()

	/**
	 * The status, end date and last activity a case seed asks for, when it does.
	 *
	 * @param DemoSeedRun $run      The seed.
	 * @param array       $caseSeed The case seed entry.
	 *
	 * @return array<string, string>
	 */
	private function lifecycleFields(DemoSeedRun $run, array $caseSeed): array {
		$data = [];
		$caseTypeId = $run->caseTypes[(string)($caseSeed['caseType'] ?? '')]['id'];
		$statusId = ($run->statuses[$caseTypeId . '|' . (string)($caseSeed['status'] ?? '')] ?? '');
		if ($statusId !== '') {
			$data['status'] = $statusId;
		}

		if (isset($caseSeed['endInDays']) === true) {
			$data['endDate'] = $this->offset(now: $run->now, days: (int)$caseSeed['endInDays'])->format('Y-m-d');
		}

		if (isset($caseSeed['lastActivityInDays']) === true) {
			$data['lastActivityAt'] = $this->offset(now: $run->now, days: (int)$caseSeed['lastActivityInDays'])
				->format(DateTimeImmutable::ATOM);
		}

		return $data;
	}//end lifecycleFields()

	/**
	 * Create the tasks belonging to one case.
	 *
	 * @param string $caseId The parent case id.
	 * @param array $tasks The task seed entries.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return int How many tasks were created.
	 */
	private function createTasks(
		string $caseId,
		array $tasks,
		DateTimeImmutable $now,
	): int {
		$created = 0;
		$actor = $this->userSession->getUser()?->getUID();

		foreach ($tasks as $taskSeed) {
			// `mirrorImport`, not `mirrorCreate`. The engine's create path
			// refuses a task born in a terminal state, and it is right to:
			// a task reaches `completed` through a lifecycle verb. Demo
			// tasks are asserted into whatever state the caseload needs to
			// look realistic, including completed ones, and `import` is the
			// engine's own trusted path for exactly that.
			$id = $this->engineTask->mirrorImport(
				task: $this->taskPayload(taskSeed: $taskSeed, caseId: $caseId, now: $now),
				caseId: $caseId,
				actor: $actor
			);

			if ($id !== '') {
				$created++;
				continue;
			}

			$this->logger->warning(
				'Dossiq: the engine refused a demo task',
				['case' => $caseId, 'title' => ($taskSeed['title'] ?? ''), 'reason' => $this->engineTask->lastError()]
			);
		}

		return $created;
	}//end createTasks()

	/**
	 * Build the payload for one task.
	 *
	 * @param array $taskSeed The task seed entry.
	 * @param string $caseId The parent case id.
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return array The task payload.
	 */
	private function taskPayload(array $taskSeed, string $caseId, DateTimeImmutable $now): array {
		$data = [
			'title' => (string)($taskSeed['title'] ?? ''),
			'description' => (string)($taskSeed['description'] ?? ''),
			'case' => $caseId,
			'assignee' => (string)($taskSeed['assignee'] ?? ''),
			'status' => (string)($taskSeed['status'] ?? 'available'),
			'priority' => (string)($taskSeed['priority'] ?? 'normal'),
		];

		if (isset($taskSeed['dueInDays']) === true) {
			$data['dueDate'] = $this->offset(now: $now, days: (int)$taskSeed['dueInDays'])
				->format('Y-m-d\TH:i:sP');
		}

		if (isset($taskSeed['completedInDays']) === true) {
			$data['completedDate'] = $this->offset(now: $now, days: (int)$taskSeed['completedInDays'])
				->format('Y-m-d\TH:i:sP');
		}

		return $data;
	}//end taskPayload()

	/**
	 * Work out the start date that puts a case in its intended bucket.
	 *
	 * A seed entry gives EITHER `startInDays` (the case simply opened then) OR
	 * `deadlineInDays` (the case must land in a deadline bucket). For the second
	 * form the start date is the wanted deadline minus the case type's own
	 * processing deadline, because OpenRegister recomputes
	 * `deadline = startDate + processingDeadline` on every save.
	 *
	 * @param array $caseSeed The case seed entry.
	 * @param string $processingDeadline The case type's ISO-8601 duration, e.g. "P56D".
	 * @param DateTimeImmutable $now The clock.
	 *
	 * @return DateTimeImmutable The start date to write.
	 */
	private function resolveStartDate(
		array $caseSeed,
		string $processingDeadline,
		DateTimeImmutable $now,
	): DateTimeImmutable {
		if (isset($caseSeed['startInDays']) === true) {
			return $this->offset(now: $now, days: (int)$caseSeed['startInDays']);
		}

		$deadline = $this->offset(now: $now, days: (int)($caseSeed['deadlineInDays'] ?? 0));

		try {
			return $deadline->sub(new DateInterval($processingDeadline));
		} catch (\Exception $e) {
			// A case type with no usable processing deadline cannot be placed in
			// a bucket, so open the case today and say so rather than guessing.
			$this->logger->warning(
				'Dossiq: Demo seed could not read a processing deadline, opening the case today',
				['case' => ($caseSeed['title'] ?? ''), 'processingDeadline' => $processingDeadline]
			);
			return $now;
		}
	}//end resolveStartDate()

	/**
	 * Shift the clock by a whole number of days, forwards or backwards.
	 *
	 * @param DateTimeImmutable $now The clock.
	 * @param integer $days The offset in days, negative for the past.
	 *
	 * @return DateTimeImmutable The shifted moment.
	 *
	 * @psalm-suppress FalsableReturnStatement
	 */
	private function offset(DateTimeImmutable $now, int $days): DateTimeImmutable {
		// 🔴 THE TWO ANALYZERS DISAGREE ABOUT THIS LINE, and phpstan is the
		// one that is right about the runtime.
		//
		// Since PHP 8.3 `modify()` THROWS on a bad format rather than
		// returning false, so the old `=== false` guard was unreachable and
		// phpstan reported it as an always-false comparison. Psalm's stub
		// still models the pre-8.3 signature and so believes the return can
		// be false. Dossiq's CI matrix is 8.3 and 8.4.
		//
		// The format is `%+d days` over an int and cannot be malformed, so
		// nothing here can throw either.
		//
		return $now->modify(sprintf('%+d days', $days));
	}//end offset()

	/**
	 * Read and decode the shipped dataset.
	 *
	 * @return array The decoded dataset.
	 *
	 * @throws RuntimeException When the file is missing or not valid JSON.
	 */
	private function readSeedFile(): array {
		$path = (__DIR__ . self::SEED_FILE);
		if (is_file($path) === false) {
			throw new RuntimeException('The demo caseload dataset is missing: ' . $path);
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new RuntimeException('The demo caseload dataset could not be read: ' . $path);
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			throw new RuntimeException('The demo caseload dataset is not valid JSON: ' . $path);
		}

		return $data;
	}//end readSeedFile()

	/**
	 * Installed case types keyed by their identifier.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array<string, string> $ids The register and schema ids.
	 *
	 * @return array<string, array{id: string, processingDeadline: string}> The case types.
	 */
	private function caseTypesByIdentifier(object $objectService, array $ids): array {
		$map = [];

		$caseTypes = $this->gateway->findMany(
			objectService: $objectService,
			registerId: $ids['register'],
			schemaId: $ids['caseType'],
			filters: []
		);

		foreach ($caseTypes as $caseType) {
			$row = $this->gateway->toArray(object: $caseType);
			$identifier = (string)($row['identifier'] ?? '');
			if ($identifier === '') {
				continue;
			}

			$map[$identifier] = [
				'id' => $this->gateway->idOf(object: $caseType),
				'processingDeadline' => (string)($row['processingDeadline'] ?? ''),
			];
		}

		return $map;
	}//end caseTypesByIdentifier()

	/**
	 * Status type ids keyed by "caseTypeId|statusName".
	 *
	 * Keyed by case type as well as by name because the same status name is
	 * reused across case types: all four shipped types have an "Ontvangen", and
	 * pointing a case at another type's status would break its own transitions.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param array<string, string> $ids The register and schema ids.
	 *
	 * @return array<string, string> The status ids.
	 */
	private function statusTypesByCaseTypeAndName(object $objectService, array $ids): array {
		$map = [];

		$statusTypes = $this->gateway->findMany(
			objectService: $objectService,
			registerId: $ids['register'],
			schemaId: $ids['statusType'],
			filters: []
		);

		foreach ($statusTypes as $statusType) {
			$row = $this->gateway->toArray(object: $statusType);
			$name = $this->text->textOf(value: ($row['name'] ?? ''));
			$caseType = (string)($row['caseType'] ?? '');
			if ($name === '' || $caseType === '') {
				continue;
			}

			$map[$caseType . '|' . $name] = $this->gateway->idOf(object: $statusType);
		}

		return $map;
	}//end statusTypesByCaseTypeAndName()
}//end class
