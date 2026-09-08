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
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
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
	 * The `applicationSlug` every planned follow-up flow carries.
	 *
	 * It is the marker that separates the flows this service writes from the
	 * flows dossiq SHIPS (which carry none), and `FlowMapper::findAllFlows()`
	 * filters on it server-side. Recognising a planned flow by its NAME instead
	 * would break the moment somebody renamed one in the flow editor.
	 *
	 * @var string
	 */
	public const PLANNED_SLUG = 'dossiq-planned-follow-up';

	/**
	 * Flows read per page.
	 *
	 * @var integer
	 */
	private const PAGE = 200;

	/**
	 * The hour a planned follow-up is created on its date.
	 *
	 * @var integer
	 */
	private const PLANNED_HOUR = 6;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's services by name.
	 * @param SettingsService $settingsService Bridge to OpenRegister's object service.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
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
	 * @param string $caseId The case the follow-up belongs to.
	 * @param string $caseTypeId The planned case's type.
	 * @param string $date The date it is due, as `Y-m-d`.
	 * @param string $title The planned case's title.
	 * @param string $uid The user the run acts as, and who planned it.
	 *
	 * @return array{id: string, title: string, date: string, caseType: string} The planned follow-up.
	 *
	 * @throws RuntimeException `flows_unavailable`, `invalid_date`, `plan_failed`.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function plan(string $caseId, string $caseTypeId, string $date, string $title, string $uid): array {
		$due = $this->dueDate(date: $date);

		$service = $this->optional(name: self::FLOW_SERVICE);
		if ($service === null) {
			throw new RuntimeException('flows_unavailable');
		}

		$document = $this->planDocument(
			caseId: $caseId,
			caseTypeId: $caseTypeId,
			due: $due,
			title: $title,
			uid: $uid
		);

		try {
			$flow = $service->save(data: $document);
			$this->publish(flow: $flow);
			$service->save(data: ['enabled' => true], uuid: (string)$flow->getUuid());
		} catch (Throwable $e) {
			$this->logger->error(
				'CaseFlowActions: could not write the planned follow-up',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);

			throw new RuntimeException('plan_failed');
		}

		return [
			'id' => (string)$flow->getUuid(),
			'title' => $title,
			'date' => $due->format('Y-m-d'),
			'caseType' => $caseTypeId,
		];
	}//end plan()

	/**
	 * The follow-ups planned for this case that have not been created yet.
	 *
	 * "Not yet" is `lastRunAt === null`: a flow that has fired created its
	 * case, and that case is an ordinary related case from then on. Reading
	 * the flow's own last-run stamp rather than counting runs keeps this a
	 * single table read and gives the same answer.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{results: array<int, array{id: string, title: string, date: string, caseType: string}>, total: int} The planned rows.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function planned(string $caseId): array {
		$results = [];
		foreach ($this->plannedFlows() as $flow) {
			if ($flow->getLastRunAt() !== null) {
				continue;
			}

			$marker = $this->markerOf(flow: $flow);
			if ($marker === null || $marker['case'] !== $caseId) {
				continue;
			}

			$results[] = [
				'id' => (string)$flow->getUuid(),
				'title' => $marker['title'],
				'date' => $marker['date'],
				'caseType' => $marker['caseType'],
			];
		}//end foreach

		usort($results, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

		return ['results' => $results, 'total' => count($results)];
	}//end planned()

	/**
	 * Switch off every planned follow-up that has already fired.
	 *
	 * A schedule trigger is a five-field cron, and five fields cannot say
	 * "once": the closest a planned date can be pinned is one minute of one day
	 * of one month, which comes round again next year. So single-shot is
	 * enforced HERE, by disabling the flow after it has run, rather than
	 * pretended in the cron expression. Called by
	 * {@see \OCA\Dossiq\BackgroundJob\PlannedFollowUpSweepJob}.
	 *
	 * @return integer How many flows were switched off.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function retireFired(): int {
		$service = $this->optional(name: self::FLOW_SERVICE);
		if ($service === null) {
			return 0;
		}

		$retired = 0;
		foreach ($this->plannedFlows() as $flow) {
			if ($flow->getLastRunAt() === null || $flow->getEnabled() === false) {
				continue;
			}

			try {
				$service->save(data: ['enabled' => false], uuid: (string)$flow->getUuid());
				$retired++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'CaseFlowActions: could not retire a fired follow-up',
					['flow' => (string)$flow->getUuid(), 'exception' => $e->getMessage()]
				);
			}
		}//end foreach

		return $retired;
	}//end retireFired()

	/**
	 * The flow document a planned follow-up is written from.
	 *
	 * @param string $caseId The case the follow-up belongs to.
	 * @param string $caseTypeId The planned case's type.
	 * @param DateTimeImmutable $due The date it is due.
	 * @param string $title The planned case's title.
	 * @param string $uid The user the run acts as.
	 *
	 * @return array<string, mixed> The flow document.
	 *
	 * @spec openspec/specs/workflow-definition-engine/spec.md
	 */
	public function planDocument(
		string $caseId,
		string $caseTypeId,
		DateTimeImmutable $due,
		string $title,
		string $uid,
	): array {
		return [
			'name' => 'Planned follow-up: ' . $title,
			'description' => sprintf(
				'Creates a case of type %s on %s, related to case %s. Planned by %s.',
				$caseTypeId,
				$due->format('Y-m-d'),
				$caseId,
				$uid
			),
			'app' => Application::APP_ID,
			'applicationSlug' => self::PLANNED_SLUG,
			'trigger' => 'schedule',
			'cron' => $this->cronFor(due: $due),
			'executionMode' => 'async',
			'enabled' => false,
			'nodes' => [
				[
					'id' => 'when',
					'type' => 'openregister.trigger-schedule',
					'config' => [
						'cron' => $this->cronFor(due: $due),
						'runAs' => $uid,
					],
				],
				[
					'id' => 'create',
					'type' => 'dossiq.createSubCase',
					'config' => [
						'caseType' => $caseTypeId,
						'title' => $title,
						'relatedCases' => [$caseId],
					],
				],
				['id' => 'done', 'type' => 'openregister.end', 'config' => []],
			],
			'edges' => [
				['id' => 'e-create', 'from' => 'when', 'to' => 'create'],
				['id' => 'e-done', 'from' => 'create', 'to' => 'done'],
			],
			'limits' => ['maxTransitions' => 10],
		];
	}//end planDocument()

	/**
	 * The cron expression for one date.
	 *
	 * Minute, hour, day and month are all pinned; only the weekday field is
	 * open, because pinning it too would AND two calendar constraints that
	 * disagree in most years. See {@see self::retireFired()} for why the year
	 * cannot be pinned and what stands in for it.
	 *
	 * @param DateTimeImmutable $due The date.
	 *
	 * @return string The five-field expression.
	 */
	private function cronFor(DateTimeImmutable $due): string {
		return sprintf('0 %d %d %d *', self::PLANNED_HOUR, (int)$due->format('j'), (int)$due->format('n'));
	}//end cronFor()

	/**
	 * Validate and parse the requested date.
	 *
	 * @param string $date The date as `Y-m-d`.
	 *
	 * @return DateTimeImmutable The parsed date.
	 *
	 * @throws RuntimeException `invalid_date` when it is absent or unparseable.
	 */
	private function dueDate(string $date): DateTimeImmutable {
		$parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
		if ($parsed === false) {
			throw new RuntimeException('invalid_date');
		}

		return $parsed;
	}//end dueDate()

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

		$case = $this->fetch($objectService, $register, $caseSchema, $caseId);
		if ($case === null) {
			throw new RuntimeException('case_not_found');
		}

		$caseTypeId = (string)($case['caseType'] ?? '');
		$caseType = $this->fetch($objectService, $register, $typeSchema, $caseTypeId);
		if ($caseType === null) {
			return [];
		}

		$declared = ($caseType['startableFlows'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		$uuids = [];
		foreach ($declared as $entry) {
			$uuid = trim((string)(is_array($entry) === true ? ($entry['id'] ?? '') : $entry));
			if ($uuid !== '') {
				$uuids[] = $uuid;
			}
		}

		return $uuids;
	}//end startableFlowIds()

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
				applicationSlug: self::PLANNED_SLUG,
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
	 * What one planned flow says it will create, read off its own graph.
	 *
	 * The graph is the record: a name can be edited, the node config is what
	 * actually runs. The date comes from the schedule node's cron rather than
	 * the description for the same reason.
	 *
	 * @param object $flow The flow row.
	 *
	 * @return array{case: string, caseType: string, title: string, date: string}|null The marker, or null when the graph is not one of ours.
	 */
	private function markerOf(object $flow): ?array {
		$nodes = (array)($flow->getNodes() ?? []);
		$create = null;
		$cron = '';
		foreach ($nodes as $node) {
			$type = (string)(is_array($node) === true ? ($node['type'] ?? '') : '');
			$config = (array)(is_array($node) === true ? ($node['config'] ?? []) : []);
			if ($type === 'dossiq.createSubCase') {
				$create = $config;
			}

			if ($type === 'openregister.trigger-schedule') {
				$cron = (string)($config['cron'] ?? '');
			}
		}

		if ($create === null) {
			return null;
		}

		$related = (array)($create['relatedCases'] ?? []);
		$caseId = trim((string)($related[0] ?? ''));
		if ($caseId === '') {
			return null;
		}

		return [
			'case' => $caseId,
			'caseType' => (string)($create['caseType'] ?? ''),
			'title' => (string)($create['title'] ?? ''),
			'date' => $this->dateOfCron(cron: $cron),
		];
	}//end markerOf()

	/**
	 * The next calendar date a pinned cron expression names.
	 *
	 * @param string $cron The five-field expression.
	 *
	 * @return string The date as `Y-m-d`, or an empty string when it cannot be read.
	 */
	private function dateOfCron(string $cron): string {
		$fields = preg_split('/\s+/', trim($cron));
		if (is_array($fields) === false || count($fields) !== 5) {
			return '';
		}

		$day = (int)$fields[2];
		$month = (int)$fields[3];
		if ($day < 1 || $month < 1) {
			return '';
		}

		$year = (int)date('Y');
		$candidate = sprintf('%04d-%02d-%02d', $year, $month, $day);
		if ($candidate >= date('Y-m-d')) {
			return $candidate;
		}

		return sprintf('%04d-%02d-%02d', ($year + 1), $month, $day);
	}//end dateOfCron()

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
