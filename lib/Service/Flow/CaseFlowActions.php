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
	 * Flows read per page.
	 *
	 * @var integer
	 */
	private const PAGE = 200;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's services by name.
	 * @param SettingsService $settingsService Bridge to OpenRegister's object service.
	 * @param PlannedFollowUpDocument $document Builds and reads a planned follow-up's flow.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SettingsService $settingsService,
		private readonly PlannedFollowUpDocument $document,
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
		$due = $this->document->dueDate(date: $date);

		$service = $this->optional(name: self::FLOW_SERVICE);
		if ($service === null) {
			throw new RuntimeException('flows_unavailable');
		}

		$document = $this->document->build(
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

			$marker = $this->document->markerOf(nodes: (array)($flow->getNodes() ?? []));
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
