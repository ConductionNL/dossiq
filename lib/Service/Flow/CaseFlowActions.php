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
use OCA\Dossiq\Kernel\Contracts\CaseFlowActionsInterface;
use OCA\Dossiq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Start an allowed flow for a case, and plan a follow-up case.
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of small private steps; each method stays under the method thresholds.
 */
class CaseFlowActions implements CaseFlowActionsInterface {

	/**
	 * OpenRegister's flow service, resolved by name.
	 *
	 * @var string
	 */
	private const FLOW_SERVICE = 'OCA\\OpenRegister\\Service\\Flow\\FlowService';

	/**
	 * OpenRegister's flow version service, resolved by name.
	 *
	 * @var string
	 */
	private const FLOW_VERSION_SERVICE = 'OCA\\OpenRegister\\Service\\Flow\\FlowVersionService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's services by name.
	 * @param SettingsService $settingsService Bridge to OpenRegister's object service.
	 * @param PlannedFollowUpDocument $document Builds and reads a planned follow-up's flow.
	 * @param PlannedSeriesLedger $ledger Counts a series' firings and reads what it opened.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly PlannedFollowUpDocument $document,
		private readonly PlannedSeriesLedger $ledger,
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

		$results = $this->flowRows(service: $service, uuids: $uuids);

		return ['results' => $results, 'total' => count($results)];
	}//end startableFlows()

	/**
	 * The id, title and description of each flow that resolves.
	 *
	 * @param object             $service OpenRegister's flow service.
	 * @param array<int, string> $uuids   The flow uuids.
	 *
	 * @return array<int, array{id: string, title: string, description: string}> The flows.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	private function flowRows(object $service, array $uuids): array {
		$results = [];
		foreach ($uuids as $uuid) {
			try {
				$flow = $service->find(uuid: $uuid);
			} catch (Throwable $e) {
				// A case type may name a flow that was deleted, or one this
				// caller may not see. Skipping it is the honest answer: the
				// list is what you MAY start, and a flow you cannot reach is
				// not one of them.
				$this->logger->debug('CaseFlowActions: a startable flow did not resolve', ['flow' => $uuid, 'exception' => $e->getMessage()]);
				continue;
			}

			$results[] = $this->flowRow(flow: $flow);
		}//end foreach

		return $results;
	}//end flowRows()

	/**
	 * One flow as the startable list shows it.
	 *
	 * @param object $flow The flow entity.
	 *
	 * @return array{id: string, title: string, description: string} The flow.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	private function flowRow(object $flow): array {
		return [
			'id' => (string)$flow->getUuid(),
			'title' => (string)$flow->getName(),
			'description' => (string)($flow->getDescription() ?? ''),
		];
	}//end flowRow()

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
	 * @spec openspec/specs/workflow-definition-engine/spec.md
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

		$service = $this->flowService();

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

		$flow = $this->saveAndPublish(service: $service, document: $document, caseId: $caseId);

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
	 * OpenRegister's flow service.
	 *
	 * @return object The flow service.
	 *
	 * @throws RuntimeException `flows_unavailable` when OpenRegister has no flows.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	private function flowService(): object {
		$service = $this->optional(name: self::FLOW_SERVICE);
		if ($service === null) {
			throw new RuntimeException('flows_unavailable');
		}

		return $service;
	}//end flowService()

	/**
	 * Save the flow document, stamp the series source, publish and enable it.
	 *
	 * @param object               $service  OpenRegister's flow service.
	 * @param array<string, mixed> $document The flow document.
	 * @param string               $caseId   The case, for the log line.
	 *
	 * @return object The published flow.
	 *
	 * @throws RuntimeException `plan_failed` when any step fails.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	private function saveAndPublish(object $service, array $document, string $caseId): object {
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

		return $flow;
	}//end saveAndPublish()

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
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function planned(string $caseId): array {
		$today = new DateTimeImmutable('today');

		$results = [];
		foreach ($this->ledger->plannedFlows() as $flow) {
			$row = $this->plannedRow(flow: $flow, caseId: $caseId, today: $today);
			if ($row !== null) {
				$results[] = $row;
			}
		}

		usort($results, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

		return ['results' => $results, 'total' => count($results)];
	}//end planned()

	/**
	 * The row of a planned flow of this case still to come, or null.
	 *
	 * @param object            $flow   The flow row.
	 * @param string            $caseId The case UUID.
	 * @param DateTimeImmutable $today  Midnight today.
	 *
	 * @return array<string, mixed>|null The planned row, or null when it does not belong on the list.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	private function plannedRow(object $flow, string $caseId, DateTimeImmutable $today): ?array {
		$marker = $this->markerOfCase(flow: $flow, caseId: $caseId, today: $today);
		if ($marker === null) {
			return null;
		}

		$single = $marker['recurrence'] === PlannedFollowUpDocument::RECURRENCE_NONE;
		if ($single === true && $flow->getLastRunAt() !== null) {
			return null;
		}

		$flowId = (string)$flow->getUuid();
		return [
			'id' => $flowId,
			'title' => $marker['title'],
			'date' => $marker['date'],
			'caseType' => $marker['caseType'],
			'recurrence' => $marker['recurrence'],
			'until' => $marker['until'],
			'count' => $marker['count'],
			'occurrences' => $this->ledger->occurrencesOf(flowId: $flowId, single: $single),
		];
	}//end plannedRow()

	/**
	 * The planned-follow-up marker of an enabled flow of this case, or null.
	 *
	 * @param object            $flow   The flow row.
	 * @param string            $caseId The case UUID.
	 * @param DateTimeImmutable $today  Midnight today.
	 *
	 * @return array<string, mixed>|null The marker, or null for a disabled flow or another case's.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	private function markerOfCase(object $flow, string $caseId, DateTimeImmutable $today): ?array {
		if ($flow->getEnabled() === false) {
			return null;
		}

		$marker = $this->document->markerOf(nodes: (array)($flow->getNodes() ?? []), from: $today);
		if ($marker === null || $marker['case'] !== $caseId) {
			return null;
		}

		return $marker;
	}//end markerOfCase()

	/**
	 * Stop a series, leaving the cases it has already opened alone.
	 *
	 * The gesture stays on this class because the Actions menu and the
	 * controller reach for it here, next to plan. The bookkeeping it needs
	 * lives on {@see PlannedSeriesLedger}.
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
		return $this->ledger->stopSeries(caseId: $caseId, flowId: $flowId, uid: $uid);
	}//end stopSeries()

	/**
	 * Switch off every planned follow-up that has nothing left to do.
	 *
	 * @param DateTimeImmutable|null $today The day the sweep is running on.
	 *
	 * @return integer How many flows were switched off.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function retireSpent(?DateTimeImmutable $today = null): int {
		return $this->ledger->retireSpent(today: $today);
	}//end retireSpent()

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

		$lookup = new StartableFlowLookup(objectService: $objectService, register: $register, typeSchema: $typeSchema);
		$case = $this->fetch(lookup: $lookup, schema: $caseSchema, id: $caseId);
		if ($case === null) {
			throw new RuntimeException('case_not_found');
		}

		return $this->flowIdsOfType(lookup: $lookup, caseRow: $case);
	}//end startableFlowIds()

	/**
	 * The flow uuids a case's type marks as startable; none when the type does not resolve.
	 *
	 * @param StartableFlowLookup  $lookup  The ObjectService, register and case type schema.
	 * @param array<string, mixed> $caseRow The case.
	 *
	 * @return array<int, string> The flow uuids.
	 */
	private function flowIdsOfType(StartableFlowLookup $lookup, array $caseRow): array {
		$caseTypeId = (string)($caseRow['caseType'] ?? '');
		$caseType = $this->fetch(lookup: $lookup, schema: $lookup->typeSchema, id: $caseTypeId);
		if ($caseType === null) {
			return [];
		}

		return $this->flowIdsOf(declared: ($caseType['startableFlows'] ?? []));
	}//end flowIdsOfType()

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
	 * @param StartableFlowLookup $lookup The ObjectService and register.
	 * @param string              $schema The schema slug.
	 * @param string              $id     The object id.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	private function fetch(StartableFlowLookup $lookup, string $schema, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$found = $lookup->objectService->find($id, register: $lookup->register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->debug('CaseFlowActions: object lookup failed', ['id' => $id, 'exception' => $e->getMessage()]);

			return null;
		}

		return $this->asArray(found: $found);
	}//end fetch()

	/**
	 * An object as an array, or null when there is none.
	 *
	 * @param mixed $found What the ObjectService answered.
	 *
	 * @return array<string, mixed>|null The object, or null.
	 */
	private function asArray(mixed $found): ?array {
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
	}//end asArray()
}//end class
