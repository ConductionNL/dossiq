<?php

/**
 * What a planned series has fired, and what it has opened.
 *
 * Split out of {@see \OCA\Dossiq\Service\Flow\CaseFlowActions} so that class
 * stays about the gestures a handler makes on a case, and the bookkeeping a
 * series needs lives in one place.
 *
 * 🔴 THE COUNT CANNOT LIVE ON THE FLOW. A published flow's graph is immutable:
 * `FlowService::save()` refuses a definition change unless the flow is a draft,
 * so writing the count back into the node config would be refused on every
 * sweep after the first, and refused QUIETLY enough that a counted series would
 * simply never stop. It lives in this app's config keyed by the flow uuid,
 * which is exactly where OpenRegister's own `FlowScheduleService` keeps a
 * flow's last-fire, and for the same reason.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
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
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Counts a series' firings and reads the cases it opened.
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */
class PlannedSeriesLedger {

	/**
	 * OpenRegister's flow service, resolved by name.
	 *
	 * @var string
	 */
	private const FLOW_SERVICE = 'OCA\\OpenRegister\\Service\\Flow\\FlowService';

	/**
	 * OpenRegister's flow mapper, resolved by name.
	 *
	 * @var string
	 */
	private const FLOW_MAPPER = 'OCA\\OpenRegister\\Db\\FlowMapper';

	/**
	 * Rows read per page.
	 *
	 * @var integer
	 */
	private const PAGE = 200;

	/**
	 * Config-key prefix for how many occurrences a series has created.
	 *
	 * @var string
	 */
	private const FIRED_KEY = 'planned_series_fired_';

	/**
	 * Config-key prefix for the last firing this app has already counted.
	 *
	 * The sweep runs hourly and a flow's `lastRunAt` does not change between
	 * firings, so counting on every pass would spend a yearly series in a day.
	 * The stamp is what makes one firing one increment.
	 *
	 * @var string
	 */
	private const SEEN_KEY = 'planned_series_seen_';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's services by name.
	 * @param SettingsService $settingsService Bridge to OpenRegister's object service.
	 * @param PlannedFollowUpDocument $document Reads a planned follow-up's flow.
	 * @param IAppConfig $appConfig Remembers how often a series has fired.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly PlannedFollowUpDocument $document,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * How many times a planned flow has fired, counting this firing once.
	 *
	 * @param string $flowId The flow uuid.
	 * @param mixed $lastRunAt The flow's last-run stamp.
	 *
	 * @return integer The count including the firing just observed.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function firedCount(string $flowId, mixed $lastRunAt): int {
		$stamp = '';
		if ($lastRunAt instanceof DateTimeInterface === true) {
			$stamp = $lastRunAt->format('Y-m-d H:i:s');
		}

		$seen = $this->appConfig->getValueString(Application::APP_ID, (self::SEEN_KEY . $flowId), '');
		$fired = (int)$this->appConfig->getValueString(Application::APP_ID, (self::FIRED_KEY . $flowId), '0');
		if ($seen === $stamp) {
			return $fired;
		}

		$fired++;
		$this->appConfig->setValueString(Application::APP_ID, (self::SEEN_KEY . $flowId), $stamp);
		$this->appConfig->setValueString(Application::APP_ID, (self::FIRED_KEY . $flowId), (string)$fired);

		return $fired;
	}//end firedCount()

	/**
	 * The cases one series has already opened.
	 *
	 * Read by `handoffSource`, which every occurrence carries as
	 * `planned-series:<flowId>`, so the cases of one series are one query
	 * rather than a scan of every related case asking where it came from.
	 *
	 * @param string $flowId The series flow's uuid.
	 * @param boolean $single Whether this is a single follow-up rather than a series.
	 *
	 * @return array<int, array{id: string, title: string}> The occurrences.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function occurrencesOf(string $flowId, bool $single): array {
		if ($single === true) {
			// A single follow-up that has fired is not in the list at all, so
			// asking the store what it created would always answer nothing.
			return [];
		}

		return $this->shape(rows: $this->rowsOf(flowId: $flowId));
	}//end occurrencesOf()

	/**
	 * The raw case rows one series opened, or none when the store cannot answer.
	 *
	 * @param string $flowId The series flow's uuid.
	 *
	 * @return array<int, mixed> The rows.
	 */
	private function rowsOf(string $flowId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		try {
			$rows = $objectService->findAll(
				[
					'filters' => [
						'register' => $register,
						'schema' => $schema,
						'handoffSource' => (PlannedFollowUpDocument::SERIES_SOURCE . $flowId),
					],
					'limit' => self::PAGE,
				],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PlannedSeriesLedger: could not read the cases a series created',
				['flow' => $flowId, 'exception' => $e->getMessage()]
			);

			return [];
		}

		if (is_array($rows) === true && isset($rows['results']) === true) {
			return (array)$rows['results'];
		}

		return (array)$rows;
	}//end rowsOf()

	/**
	 * The rows as `{id, title}` pairs, dropping anything with no id.
	 *
	 * @param array<int, mixed> $rows The raw rows.
	 *
	 * @return array<int, array{id: string, title: string}> The occurrences.
	 */
	private function shape(array $rows): array {
		$occurrences = [];
		foreach ($rows as $row) {
			$case = $this->asArray(row: $row);
			$id = trim((string)($case['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			$occurrences[] = ['id' => $id, 'title' => (string)($case['title'] ?? '')];
		}

		return $occurrences;
	}//end shape()

	/**
	 * One `findAll()` row as an array, entity or not.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The payload.
	 */
	private function asArray(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$serialized = $row->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return (array)$row;
	}//end asArray()

	/**
	 * Every planned-follow-up flow, whichever app stored it.
	 *
	 * Found by the slug alone: an example set stores its flows for the app
	 * that ships the fragment, so a series seeded beside a portal case is
	 * that app's row and carries the same slug.
	 *
	 * @return array<int, object> The flow rows.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function plannedFlows(): array {
		$mapper = $this->optional(name: self::FLOW_MAPPER);
		if ($mapper === null) {
			return [];
		}

		try {
			return (array)$mapper->findAllFlows(
				applicationSlug: PlannedFollowUpDocument::PLANNED_SLUG,
				limit: self::PAGE
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PlannedSeriesLedger: could not read the planned follow-ups',
				['exception' => $e->getMessage()]
			);

			return [];
		}
	}//end plannedFlows()

	/**
	 * Stop a series, leaving the cases it has already opened alone.
	 *
	 * Stopping is switching the flow off, not deleting it: the occurrences it
	 * created are real cases somebody is working on, and the record of what
	 * opened them is the flow. The reason is written to the flow's `notes`,
	 * which is an editable field rather than part of the graph — a published
	 * flow's graph is frozen, so a reason written into a node would be refused.
	 *
	 * @param string $caseId The case the series belongs to.
	 * @param string $flowId The series flow's uuid.
	 * @param string $uid Who stopped it.
	 *
	 * @return array{id: string, stopped: bool} The stopped series.
	 *
	 * @throws RuntimeException `flows_unavailable`, `case_not_found`, `stop_failed`.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function stopSeries(string $caseId, string $flowId, string $uid): array {
		$service = $this->optional(name: self::FLOW_SERVICE);
		if ($service === null) {
			throw new RuntimeException('flows_unavailable');
		}

		$flow = $this->seriesOfCase(caseId: $caseId, flowId: $flowId);
		if ($flow === null) {
			throw new RuntimeException('case_not_found');
		}

		try {
			$service->save(
				data: [
					'enabled' => false,
					'notes' => sprintf('Series stopped by %s on %s.', $uid, date('Y-m-d')),
				],
				uuid: $flowId
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'PlannedSeriesLedger: could not stop a series',
				['flow' => $flowId, 'exception' => $e->getMessage()]
			);

			throw new RuntimeException('stop_failed');
		}

		return ['id' => $flowId, 'stopped' => true];
	}//end stopSeries()

	/**
	 * The series flow with this uuid among THIS case's planned flows, or null.
	 *
	 * The series is found through THIS case's planned flows rather than by
	 * uuid alone. A uuid the caller supplies is a uuid the caller chose, and
	 * the guard at the controller only proves they may act on $caseId.
	 *
	 * @param string $caseId The case the series belongs to.
	 * @param string $flowId The series flow's uuid.
	 *
	 * @return object|null The flow; the last match when several carry the uuid.
	 */
	private function seriesOfCase(string $caseId, string $flowId): ?object {
		$flow = null;
		foreach ($this->plannedFlows() as $candidate) {
			if ((string)$candidate->getUuid() === $flowId && $this->plannedFor(flow: $candidate, caseId: $caseId) === true) {
				$flow = $candidate;
			}
		}

		return $flow;
	}//end seriesOfCase()

	/**
	 * Whether a planned flow's marker names this case.
	 *
	 * @param object $flow   The planned flow.
	 * @param string $caseId The case.
	 *
	 * @return bool True when the flow was planned on the case.
	 */
	private function plannedFor(object $flow, string $caseId): bool {
		$marker = $this->document->markerOf(nodes: (array)($flow->getNodes() ?? []));

		return ($marker !== null && $marker['case'] === $caseId);
	}//end plannedFor()

	/**
	 * Switch off every planned follow-up that has nothing left to do.
	 *
	 * A schedule trigger is a five-field cron, and five fields cannot say
	 * "once" or "three times": the closest a planned date can be pinned is one
	 * minute of one day of one month, which comes round again next year. So the
	 * end of a planned follow-up is enforced HERE rather than pretended in the
	 * cron expression — a single follow-up ends after one firing, a series when
	 * its count is reached or its end date has passed, and a series with
	 * neither only when somebody stops it. Called by
	 * {@see \OCA\Dossiq\BackgroundJob\PlannedFollowUpSweepJob}.
	 *
	 * @param DateTimeImmutable|null $today The day to judge against, injectable for tests.
	 *
	 * @return integer How many flows were switched off.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function retireSpent(?DateTimeImmutable $today = null): int {
		$service = $this->optional(name: self::FLOW_SERVICE);
		if ($service === null) {
			return 0;
		}

		$sweep = new RetirementSweep(flowService: $service, day: ($today ?? new DateTimeImmutable('today')));
		$retired = 0;
		foreach ($this->plannedFlows() as $flow) {
			$retired += $this->retireIfSpent(sweep: $sweep, flow: $flow);
		}

		return $retired;
	}//end retireSpent()

	/**
	 * Switch off one planned follow-up that has fired and has nothing left to do.
	 *
	 * @param RetirementSweep $sweep The sweep.
	 * @param object          $flow  The planned flow.
	 *
	 * @return integer 1 when it was switched off, 0 otherwise.
	 */
	private function retireIfSpent(RetirementSweep $sweep, object $flow): int {
		if ($flow->getLastRunAt() === null || $flow->getEnabled() === false) {
			return 0;
		}

		if ($this->isSpent(sweep: $sweep, flow: $flow) === false) {
			return 0;
		}

		return $this->retire(sweep: $sweep, flow: $flow);
	}//end retireIfSpent()

	/**
	 * Whether a planned flow has fired as often, or as long, as it was meant to.
	 *
	 * @param RetirementSweep $sweep The sweep.
	 * @param object          $flow  The planned flow.
	 *
	 * @return bool True when it is spent.
	 */
	private function isSpent(RetirementSweep $sweep, object $flow): bool {
		$nodes = (array)($flow->getNodes() ?? []);
		$fired = $this->firedCount(flowId: (string)$flow->getUuid(), lastRunAt: $flow->getLastRunAt());

		return $this->document->isSpent(document: ['nodes' => $nodes], firedCount: $fired, today: $sweep->day);
	}//end isSpent()

	/**
	 * Switch a spent flow off with the reason in its notes; a failed save is logged.
	 *
	 * @param RetirementSweep $sweep The sweep.
	 * @param object          $flow  The spent flow.
	 *
	 * @return integer 1 when it was switched off, 0 when the save failed.
	 */
	private function retire(RetirementSweep $sweep, object $flow): int {
		$flowId = (string)$flow->getUuid();
		$series = $this->document->seriesOf(nodes: (array)($flow->getNodes() ?? []));
		$reason = 'follow-up created';
		if ($series['recurrence'] !== PlannedFollowUpDocument::RECURRENCE_NONE) {
			$reason = 'series complete';
		}

		try {
			$sweep->flowService->save(data: ['enabled' => false, 'notes' => $reason], uuid: $flowId);
			return 1;
		} catch (Throwable $e) {
			$this->logger->warning(
				'PlannedSeriesLedger: could not retire a spent follow-up',
				['flow' => $flowId, 'exception' => $e->getMessage()]
			);
			return 0;
		}
	}//end retire()


	/**
	 * Resolve an OpenRegister collaborator, or null when it is not there.
	 *
	 * @param string $name The fully-qualified class name.
	 *
	 * @return object|null The service, or null.
	 */
	private function optional(string $name): ?object {
		try {
			return $this->container->get($name);
		} catch (Throwable $e) {
			$this->logger->debug(
				'PlannedSeriesLedger: OpenRegister collaborator unavailable',
				['class' => $name, 'exception' => $e->getMessage()]
			);

			return null;
		}
	}//end optional()
}//end class
