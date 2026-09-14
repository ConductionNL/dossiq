<?php

/**
 * Dossiq case flow actions.
 *
 * The two flow-shaped entries in a case's Actions menu: Start, which runs a
 * flow the case type allows against this case, and Plan follow-up, which
 * writes one scheduled flow that opens a case on a later date.
 *
 * 🔴 EVERY OpenRegister CLASS IS RESOLVED BY NAME. Dossiq declares no `<app>`
 * dependency on openregister, so a type-hinted constructor argument would make
 * this class unconstructible — and therefore every route that reaches it a
 * 500 — on an instance where openregister is absent. The sibling
 * {@see ShippedFlowAdoption} states the same rule for the same reason.
 *
 * 🔑 A FLOW IS A ROW, NOT A REGISTER OBJECT. Flows live in
 * `oc_openregister_flows` and are read through `FlowService` / `FlowMapper`,
 * never through `ObjectService`. That is why `caseType.startableFlows` holds
 * flow UUIDs as plain strings rather than a `$ref`: a `$ref` addresses a schema
 * in a register, and there is no `flow` schema for it to name.
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
 * Start an allowed flow for a case, and plan a follow-up case.
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */
class CaseFlowActions {

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
	 * OpenRegister's flow version service, resolved by name.
	 *
	 * @var string
	 */
	private const FLOW_VERSION_SERVICE = 'OCA\\OpenRegister\\Service\\Flow\\FlowVersionService';

	/**
	 * Flows read per page.
	 *
	 * @var integer
	 */
	private const PAGE = 200;

	/**
	 * Config-key prefix for how many occurrences a series has created.
	 *
	 * 🔴 THE COUNT CANNOT LIVE ON THE FLOW. A published flow's graph is
	 * immutable — `FlowService::save()` refuses a definition change unless the
	 * flow is a draft — so writing the count back into the node config would be
	 * refused on every sweep after the first, and refused QUIETLY enough that a
	 * counted series would simply never stop. It lives in this app's config
	 * keyed by the flow uuid, which is exactly where OpenRegister's own
	 * `FlowScheduleService` keeps a flow's last-fire, and for the same reason.
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
	 * @param PlannedFollowUpDocument $document Builds and reads a planned follow-up's flow.
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
	 * The flows this case's type allows a handler to start.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{results: array<int, array{id: string, title: string, description: string}>, total: int} The startable flows.
	 *
	 * @throws RuntimeException `case_not_found` when the case or its type does not resolve.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function startableFlows(string $caseId): array {
		$uuids = $this->startableFlowIds(caseId: $caseId);
		if ($uuids === []) {
			return ['results' => [], 'total' => 0];
		}

		$service = $this->optional(name: self::FLOW_SERVICE);
		if ($service === null) {
			return ['results' => [], 'total' => 0];
		}

		$results = [];
		foreach ($uuids as $uuid) {
			try {
				$flow = $service->find(uuid: $uuid);
			} catch (Throwable $e) {
				// A case type may name a flow that was deleted, or one this
				// caller may not see. Skipping it is the honest answer: the
				// list is what you MAY start, and a flow you cannot reach is
				// not one of them.
				$this->logger->debug(
					'CaseFlowActions: a startable flow did not resolve',
					['flow' => $uuid, 'exception' => $e->getMessage()]
				);
				continue;
			}

			$results[] = [
				'id' => (string)$flow->getUuid(),
				'title' => (string)$flow->getName(),
				'description' => (string)($flow->getDescription() ?? ''),
			];
		}//end foreach

		return ['results' => $results, 'total' => count($results)];
	}//end startableFlows()

	/**
	 * Plan a follow-up case for a later date.
	 *
	 * Writes ONE scheduled flow: a schedule trigger pinned to the chosen date
	 * feeding dossiq's `createSubCase` node. The trigger carries an explicit
	 * `runAs` — OpenRegister's TriggerScheduleNode refuses to save without one
	 * and does NOT fall back to the flow's owner, because authoring a flow is
	 * not consent to unattended execution as its author.
	 *
	 * A recurrence makes it a SERIES: the same flow, cron fields that come round
	 * again, and an end the sweep enforces. The recurrence is a token from a
	 * fixed list, never a cron expression somebody typed — a five-field
	 * expression is a language, and asking a case handler to write one is how a
	 * yearly permit check ends up firing every day in January.
	 *
	 * @param string $caseId The case the follow-up belongs to.
	 * @param string $caseTypeId The planned case's type.
	 * @param string $date The date it is due, as `Y-m-d`.
	 * @param string $title The planned case's title.
	 * @param string $uid The user the run acts as, and who planned it.
	 * @param string $recurrence `none`, `monthly`, `quarterly`, `halfYearly` or `yearly`.
	 * @param string $until The last date the series may fire on, as `Y-m-d`, or the empty string.
	 * @param integer $count How many occurrences the series runs for, or 0.
	 *
	 * @return array{id: string, title: string, date: string, caseType: string, recurrence: string, until: string, count: int} The planned follow-up.
	 *
	 * @throws RuntimeException `flows_unavailable`, `invalid_date`, `invalid_recurrence`, `invalid_end`, `plan_failed`.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function plan(
		string $caseId,
		string $caseTypeId,
		string $date,
		string $title,
		string $uid,
		string $recurrence = PlannedFollowUpDocument::RECURRENCE_NONE,
		string $until = '',
		int $count = 0,
	): array {
		$due = $this->document->dueDate(date: $date);
		$mode = $this->document->recurrenceOf(recurrence: $recurrence);
		$end = $this->document->endOf(recurrence: $mode, until: $until, count: $count);

		$service = $this->optional(name: self::FLOW_SERVICE);
		if ($service === null) {
			throw new RuntimeException('flows_unavailable');
		}

		$document = $this->document->build(
			caseId: $caseId,
			caseTypeId: $caseTypeId,
			due: $due,
			title: $title,
			uid: $uid,
			recurrence: $mode,
			until: $end['until'],
			count: $end['count']
		);

		try {
			$flow = $service->save(data: $document);

			// The uuid a series is known by does not exist until the row does,
			// so the cases it will create are stamped with it HERE — between
			// the save and the publish, because publishing freezes the graph.
			$service->save(
				data: ['nodes' => $this->document->withSeriesSource(document: $document, flowId: (string)$flow->getUuid())],
				uuid: (string)$flow->getUuid()
			);

			$flow = $service->find(uuid: (string)$flow->getUuid());
			$this->publish(flow: $flow);
			$service->save(data: ['enabled' => true], uuid: (string)$flow->getUuid());
		} catch (Throwable $e) {
			$this->logger->error(
				'CaseFlowActions: could not write the planned follow-up',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);

			throw new RuntimeException('plan_failed');
		}//end try

		return [
			'id' => (string)$flow->getUuid(),
			'title' => $title,
			'date' => $due->format('Y-m-d'),
			'caseType' => $caseTypeId,
			'recurrence' => $mode,
			'until' => $end['until'],
			'count' => $end['count'],
		];
	}//end plan()

	/**
	 * The follow-ups planned for this case that are still to come.
	 *
	 * A SINGLE follow-up drops off the list the moment it has fired: the case
	 * it created is an ordinary related case from then on, and `lastRunAt` is
	 * the one table read that says so. A SERIES stays, because a series that
	 * has fired twice still has its next occurrence in front of it, and each
	 * row carries the cases it has already opened so the two are read together.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{
	 *     results: array<int, array{
	 *         id: string,
	 *         title: string,
	 *         date: string,
	 *         caseType: string,
	 *         recurrence: string,
	 *         until: string,
	 *         count: int,
	 *         occurrences: array<int, array{id: string, title: string}>
	 *     }>,
	 *     total: int
	 * } The planned rows.
	 *
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function planned(string $caseId): array {
		$today = new DateTimeImmutable('today');

		$results = [];
		foreach ($this->plannedFlows() as $flow) {
			if ($flow->getEnabled() === false) {
				continue;
			}

			$marker = $this->document->markerOf(nodes: (array)($flow->getNodes() ?? []), from: $today);
			if ($marker === null || $marker['case'] !== $caseId) {
				continue;
			}

			$single = $marker['recurrence'] === PlannedFollowUpDocument::RECURRENCE_NONE;
			if ($single === true && $flow->getLastRunAt() !== null) {
				continue;
			}

			$flowId = (string)$flow->getUuid();
			$results[] = [
				'id' => $flowId,
				'title' => $marker['title'],
				'date' => $marker['date'],
				'caseType' => $marker['caseType'],
				'recurrence' => $marker['recurrence'],
				'until' => $marker['until'],
				'count' => $marker['count'],
				'occurrences' => $this->occurrencesOf(flowId: $flowId, single: $single),
			];
		}//end foreach

		usort($results, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

		return ['results' => $results, 'total' => count($results)];
	}//end planned()

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
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function stopSeries(string $caseId, string $flowId, string $uid): array {
		$service = $this->optional(name: self::FLOW_SERVICE);
		if ($service === null) {
			throw new RuntimeException('flows_unavailable');
		}

		// The series is found through THIS case's planned flows rather than by
		// uuid alone. A uuid the caller supplies is a uuid the caller chose, and
		// the guard at the controller only proves they may act on $caseId.
		$flow = null;
		foreach ($this->plannedFlows() as $candidate) {
			if ((string)$candidate->getUuid() !== $flowId) {
				continue;
			}

			$marker = $this->document->markerOf(nodes: (array)($candidate->getNodes() ?? []));
			if ($marker !== null && $marker['case'] === $caseId) {
				$flow = $candidate;
			}
		}

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
				'CaseFlowActions: could not stop a series',
				['flow' => $flowId, 'exception' => $e->getMessage()]
			);

			throw new RuntimeException('stop_failed');
		}

		return ['id' => $flowId, 'stopped' => true];
	}//end stopSeries()

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
	 * @spec openspec/changes/planned-case-series/specs/workflow-definition-engine/spec.md
	 */
	public function retireSpent(?DateTimeImmutable $today = null): int {
		$service = $this->optional(name: self::FLOW_SERVICE);
		if ($service === null) {
			return 0;
		}

		$day = $today;
		if ($day === null) {
			$day = new DateTimeImmutable('today');
		}

		$retired = 0;
		foreach ($this->plannedFlows() as $flow) {
			if ($flow->getLastRunAt() === null || $flow->getEnabled() === false) {
				continue;
			}

			$flowId = (string)$flow->getUuid();
			$nodes = (array)($flow->getNodes() ?? []);
			$fired = $this->countFiring(flowId: $flowId, lastRunAt: $flow->getLastRunAt());
			if ($this->document->isSpent(document: ['nodes' => $nodes], firedCount: $fired, today: $day) === false) {
				continue;
			}

			$series = $this->document->seriesOf(nodes: $nodes);
			$reason = 'follow-up created';
			if ($series['recurrence'] !== PlannedFollowUpDocument::RECURRENCE_NONE) {
				$reason = 'series complete';
			}

			try {
				$service->save(data: ['enabled' => false, 'notes' => $reason], uuid: $flowId);
				$retired++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'CaseFlowActions: could not retire a spent follow-up',
					['flow' => $flowId, 'exception' => $e->getMessage()]
				);
			}
		}//end foreach

		return $retired;
	}//end retireSpent()

	/**
	 * How many times a planned flow has fired, counting this firing once.
	 *
	 * @param string $flowId The flow uuid.
	 * @param mixed $lastRunAt The flow's last-run stamp.
	 *
	 * @return integer The count including the firing just observed.
	 */
	private function countFiring(string $flowId, mixed $lastRunAt): int {
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
	}//end countFiring()

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
	 */
	private function occurrencesOf(string $flowId, bool $single): array {
		if ($single === true) {
			// A single follow-up that has fired is not in the list at all, so
			// asking the store what it created would always answer nothing.
			return [];
		}

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
				'CaseFlowActions: could not read the cases a series created',
				['flow' => $flowId, 'exception' => $e->getMessage()]
			);

			return [];
		}

		if (is_array($rows) === true && isset($rows['results']) === true) {
			$rows = $rows['results'];
		}

		$occurrences = [];
		foreach ((array)$rows as $row) {
			$case = $this->asArray(row: $row);
			$id = trim((string)($case['id'] ?? ''));
			if ($id === '') {
				continue;
			}

			$occurrences[] = ['id' => $id, 'title' => (string)($case['title'] ?? '')];
		}

		return $occurrences;
	}//end occurrencesOf()

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
	 * The flow uuids the case's type marks as startable.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, string> The flow uuids.
	 *
	 * @throws RuntimeException `case_not_found` when the case or its type does not resolve.
	 */
	private function startableFlowIds(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		$typeSchema = $this->settingsService->getConfigValue('case_type_schema');

		if ($objectService === null || $register === '' || $caseSchema === '' || $typeSchema === '') {
			throw new RuntimeException('case_not_found');
		}

		$case = $this->fetch(
			objectService: $objectService,
			register: $register,
			schema: $caseSchema,
			id: $caseId
		);
		if ($case === null) {
			throw new RuntimeException('case_not_found');
		}

		$caseTypeId = (string)($case['caseType'] ?? '');
		$caseType = $this->fetch(
			objectService: $objectService,
			register: $register,
			schema: $typeSchema,
			id: $caseTypeId
		);
		if ($caseType === null) {
			return [];
		}

		return $this->flowIdsOf(declared: ($caseType['startableFlows'] ?? []));
	}//end startableFlowIds()

	/**
	 * The flow uuids in a case type's `startableFlows`.
	 *
	 * @param mixed $declared The property as stored.
	 *
	 * @return array<int, string> The uuids.
	 */
	private function flowIdsOf(mixed $declared): array {
		if (is_array($declared) === false) {
			return [];
		}

		$uuids = [];
		foreach ($declared as $entry) {
			// An entry is a plain uuid, but a hand-edited case type may carry the
			// object shape an OpenRegister picker writes, so both are read.
			$uuid = $entry;
			if (is_array($entry) === true) {
				$uuid = ($entry['id'] ?? '');
			}

			$uuid = trim((string)$uuid);
			if ($uuid !== '') {
				$uuids[] = $uuid;
			}
		}

		return $uuids;
	}//end flowIdsOf()

	/**
	 * Every planned-follow-up flow this app owns.
	 *
	 * @return array<int, object> The flow rows.
	 */
	private function plannedFlows(): array {
		$mapper = $this->optional(name: self::FLOW_MAPPER);
		if ($mapper === null) {
			return [];
		}

		try {
			return (array)$mapper->findAllFlows(
				app: Application::APP_ID,
				applicationSlug: PlannedFollowUpDocument::PLANNED_SLUG,
				limit: self::PAGE
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CaseFlowActions: could not read the planned follow-ups',
				['exception' => $e->getMessage()]
			);

			return [];
		}
	}//end plannedFlows()

	/**
	 * Publish the flow so a schedule may run it.
	 *
	 * A run is refused unless a PUBLISHED, sound version exists
	 * (`FlowRunVersionPin::requirePublishedAndSound`), so a planned follow-up
	 * left as a draft would sit there and never fire — the exact failure this
	 * change exists to avoid.
	 *
	 * @param object $flow The stored flow.
	 *
	 * @return void
	 */
	private function publish(object $flow): void {
		$versions = $this->optional(name: self::FLOW_VERSION_SERVICE);
		if ($versions === null) {
			return;
		}

		try {
			$versions->publish(flow: $flow);
		} catch (Throwable $e) {
			$this->logger->warning(
				'CaseFlowActions: could not publish the planned follow-up',
				['flow' => (string)$flow->getUuid(), 'exception' => $e->getMessage()]
			);
		}
	}//end publish()

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
				'CaseFlowActions: OpenRegister collaborator unavailable',
				['class' => $name, 'exception' => $e->getMessage()]
			);

			return null;
		}
	}//end optional()

	/**
	 * Fetch one object, tolerating a fail-closed miss.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register The register slug.
	 * @param string $schema The schema slug.
	 * @param string $id The object id.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	private function fetch(object $objectService, string $register, string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$found = $objectService->find($id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->debug(
				'CaseFlowActions: object lookup failed',
				['id' => $id, 'exception' => $e->getMessage()]
			);

			return null;
		}

		if ($found === null) {
			return null;
		}

		if (is_array($found) === true) {
			return $found;
		}

		if (method_exists($found, 'jsonSerialize') === true) {
			$serialized = $found->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return (array)$found;
	}//end fetch()
}//end class
