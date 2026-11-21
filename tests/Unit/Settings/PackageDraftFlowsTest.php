<?php

/**
 * A package flow shipped as a draft is offered to nobody until it is published.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Workflow\TransitionAuthorizationStamper;
use OCA\Dossiq\Service\Workflow\WorkflowDefinitionRepository;
use OCA\Dossiq\Service\Workflow\WorkflowJsonProperty;
use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;
use OCA\Dossiq\Service\WorkflowDefinitionService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Sweeps every shipped case definition package.
 *
 * A package may ship a flow as a draft, for an administrator to finish and
 * publish. Whether a case follows a flow is decided in one place,
 * {@see WorkflowDefinitionService::listActiveDefinitionsFor()}, so each
 * package's flows are handed to the REAL service as the rows the instance
 * would hold after the install. A draft that comes back, a draft a case type
 * names as its default, or a flow whose three lifecycle fields disagree, is a
 * draft somebody is offered.
 *
 * @coversNothing
 *
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */
class PackageDraftFlowsTest extends TestCase {

	/**
	 * Every shipped package's flows, grouped by the case type they belong to.
	 *
	 * @return array<string, array{where: string, caseType: array<string, mixed>, flows: list<array<string, mixed>>}>
	 *         By case type id.
	 */
	private function flowsByCaseType(): array {
		$grouped = [];
		foreach ((array)glob(__DIR__ . '/../../../lib/Settings/packages/*', GLOB_ONLYDIR) as $dir) {
			$files = (array)glob($dir . '/flows/*.json');
			if ($files === []) {
				continue;
			}

			$caseTypes = [];
			foreach (array_merge(ShippedPackageCaseTypes::nested(), ShippedPackageCaseTypes::seededByRegister()) as $caseType) {
				$caseTypes[(string)($caseType['id'] ?? '')] = $caseType;
			}

			foreach ($files as $file) {
				$where = basename((string)$dir) . '/flows/' . basename((string)$file);
				$flow = json_decode((string)file_get_contents((string)$file), true);
				self::assertIsArray($flow, $where . ' must parse');
				$flow['_where'] = $where;

				$caseTypeId = (string)($flow['caseType'] ?? '');
				self::assertArrayHasKey($caseTypeId, $caseTypes, $where . ' names a case type no package or register.d seed ships');
				$grouped[$caseTypeId] ??= ['where' => basename((string)$dir), 'caseType' => $caseTypes[$caseTypeId], 'flows' => []];
				$grouped[$caseTypeId]['flows'][] = $flow;
			}
		}//end foreach

		self::assertNotSame([], $grouped, 'No package carries a flow, so this sweep would pass on anything.');

		return $grouped;
	}//end flowsByCaseType()

	/**
	 * The real service, reading one case type and its flows as stored rows.
	 *
	 * @param array<string, mixed>       $caseType The case type.
	 * @param list<array<string, mixed>> $flows    Its flows.
	 *
	 * @return WorkflowDefinitionService The service.
	 */
	private function serviceOver(array $caseType, array $flows): WorkflowDefinitionService {
		$repository = $this->createMock(WorkflowDefinitionRepository::class);
		$repository->method('listVersionsForCaseType')->willReturn($flows);
		$repository->method('findCaseType')->willReturn($caseType);

		return new WorkflowDefinitionService(
			$repository,
			new WorkflowLifecycleGuard($repository, new NullLogger()),
			$this->createMock(TransitionAuthorizationStamper::class),
			new WorkflowJsonProperty(),
			new NullLogger(),
		);
	}//end serviceOver()

	/**
	 * The lifecycle a flow file states.
	 *
	 * @param array<string, mixed> $flow The flow.
	 *
	 * @return string The lifecycle.
	 */
	private function lifecycleOf(array $flow): string {
		return (string)($flow['lifecycleStatus'] ?? '');
	}//end lifecycleOf()

	/**
	 * A draft flow is not among the flows a case of its case type can follow.
	 *
	 * @return void
	 */
	public function testADraftFlowIsNotAmongTheFlowsACaseCanFollow(): void {
		$drafts = 0;
		$offered = [];
		foreach ($this->flowsByCaseType() as $caseTypeId => $group) {
			$service = $this->serviceOver(caseType: $group['caseType'], flows: $group['flows']);
			$active = array_column($service->listActiveDefinitionsFor(caseTypeId: $caseTypeId), null, 'id');
			$default = $service->getActiveDefinitionFor(caseTypeId: $caseTypeId);

			foreach ($group['flows'] as $flow) {
				if ($this->lifecycleOf(flow: $flow) !== WorkflowDefinitionService::STATUS_DRAFT) {
					continue;
				}

				$drafts++;
				if (isset($active[(string)$flow['id']]) === true) {
					$offered[] = $flow['_where'] . ' is a draft and a case can follow it';
				}

				if ($default !== null && (string)($default['id'] ?? '') === (string)$flow['id']) {
					$offered[] = $flow['_where'] . ' is a draft and is the default flow of its case type';
				}

				$route = $service->getActiveDefinitionFor(caseTypeId: $caseTypeId, variant: (string)($flow['variant'] ?? ''));
				if ($route !== null && (string)($route['id'] ?? '') === (string)$flow['id']) {
					$offered[] = $flow['_where'] . ' is a draft and answers for its route';
				}
			}//end foreach
		}//end foreach

		self::assertGreaterThan(0, $drafts, 'No package ships a draft flow, so this sweep would pass on anything.');
		self::assertSame([], $offered, implode("\n", $offered));
	}//end testADraftFlowIsNotAmongTheFlowsACaseCanFollow()

	/**
	 * A published flow next to a draft is still what a case follows.
	 *
	 * The control for the sweep above: a service that answered nothing at all
	 * would offer no draft either.
	 *
	 * @return void
	 */
	public function testAPublishedFlowNextToADraftIsStillFollowed(): void {
		$checked = 0;
		foreach ($this->flowsByCaseType() as $caseTypeId => $group) {
			$lifecycles = array_map(fn (array $flow): string => $this->lifecycleOf(flow: $flow), $group['flows']);
			if (in_array(WorkflowDefinitionService::STATUS_DRAFT, $lifecycles, true) === false
				|| in_array(WorkflowDefinitionService::STATUS_PUBLISHED, $lifecycles, true) === false
			) {
				continue;
			}

			$checked++;
			$default = $this->serviceOver(caseType: $group['caseType'], flows: $group['flows'])
				->getActiveDefinitionFor(caseTypeId: $caseTypeId);
			self::assertNotNull($default, $group['where'] . ': case type ' . $caseTypeId . ' has a published flow and none is followed');
			self::assertSame(WorkflowDefinitionService::STATUS_PUBLISHED, $this->lifecycleOf(flow: $default));
		}

		self::assertGreaterThan(0, $checked, 'No case type ships a published flow next to a draft.');
	}//end testAPublishedFlowNextToADraftIsStillFollowed()

	/**
	 * A flow's three lifecycle fields say the same thing.
	 *
	 * `lifecycleStatus` is what the service reads first; `isDraft` and
	 * `isActive` are what the flow editor and rows older than the enum read. A
	 * file where they disagree is a draft to one reader and published to
	 * another.
	 *
	 * @return void
	 */
	public function testTheLifecycleFieldsOfAFlowAgree(): void {
		$expected = [
			WorkflowDefinitionService::STATUS_DRAFT => ['isDraft' => true, 'isActive' => false],
			WorkflowDefinitionService::STATUS_PUBLISHED => ['isDraft' => false, 'isActive' => true],
		];

		$wrong = [];
		foreach ($this->flowsByCaseType() as $group) {
			foreach ($group['flows'] as $flow) {
				$lifecycle = $this->lifecycleOf(flow: $flow);
				if (isset($expected[$lifecycle]) === false) {
					$wrong[] = $flow['_where'] . ' has lifecycleStatus "' . $lifecycle . '"; a package ships draft or published';
					continue;
				}

				foreach ($expected[$lifecycle] as $field => $value) {
					if (($flow[$field] ?? null) !== $value) {
						$wrong[] = $flow['_where'] . ' is ' . $lifecycle . ' but ' . $field . ' is ' . json_encode($flow[$field] ?? null);
					}
				}
			}
		}

		self::assertSame([], $wrong, implode("\n", $wrong));
	}//end testTheLifecycleFieldsOfAFlowAgree()

	/**
	 * A case type names a published flow of its own as its default, or none.
	 *
	 * @return void
	 */
	public function testNoCaseTypeNamesADraftAsItsDefaultFlow(): void {
		$wrong = [];
		foreach ($this->flowsByCaseType() as $caseTypeId => $group) {
			$pinned = (string)($group['caseType']['workflowDefinition'] ?? '');
			if ($pinned === '') {
				continue;
			}

			$flows = array_column($group['flows'], null, 'id');
			if (isset($flows[$pinned]) === false) {
				$wrong[] = $group['where'] . ': case type ' . $caseTypeId . ' names the flow ' . $pinned . ', which is not one of its flows';
				continue;
			}

			if ($this->lifecycleOf(flow: $flows[$pinned]) !== WorkflowDefinitionService::STATUS_PUBLISHED) {
				$wrong[] = $flows[$pinned]['_where'] . ' is not published and its case type names it as the default';
			}
		}

		self::assertSame([], $wrong, implode("\n", $wrong));
	}//end testNoCaseTypeNamesADraftAsItsDefaultFlow()

	/**
	 * A route of a case type has one published flow at most.
	 *
	 * Two published flows on one route are two answers to "which flow does this
	 * case follow", and the pick between them is by version, not by intent.
	 *
	 * @return void
	 */
	public function testARouteHasOnePublishedFlowAtMost(): void {
		$wrong = [];
		foreach ($this->flowsByCaseType() as $caseTypeId => $group) {
			$perRoute = [];
			foreach ($group['flows'] as $flow) {
				if ($this->lifecycleOf(flow: $flow) !== WorkflowDefinitionService::STATUS_PUBLISHED) {
					continue;
				}

				$route = trim((string)($flow['variant'] ?? ''));
				if ($route === '') {
					$route = WorkflowDefinitionService::VARIANT_DEFAULT;
				}

				$perRoute[$route][] = $flow['_where'];
			}

			foreach ($perRoute as $route => $files) {
				if (count($files) > 1) {
					$wrong[] = 'case type ' . $caseTypeId . ', route ' . $route . ': ' . implode(' and ', $files);
				}
			}
		}

		self::assertSame([], $wrong, implode("\n", $wrong));
	}//end testARouteHasOnePublishedFlowAtMost()
}//end class
