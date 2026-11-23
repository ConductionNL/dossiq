<?php

/**
 * Which route a workflow definition is on, and which route a case type takes by default.
 *
 * A route (a "variant") is one path from intake to closure through a single
 * case type. The default route is the one `caseType.workflowDefinition`
 * records; several active routes and no usable default are answered
 * deterministically and logged. {@see WorkflowLifecycleGuard} answers the
 * rest of "what is this row really" and decides the lifecycle changes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Workflow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Workflow;

use OCA\Dossiq\Kernel\AppIdentity;
use Psr\Log\LoggerInterface;

/**
 * Reads a definition's route and picks a case type's default route.
 *
 * @spec openspec/specs/workflow-variants/spec.md
 */
final class DefaultRoute {

	/**
	 * The route a definition is on when it names none.
	 *
	 * Every definition that predates the `variant` property carries no route,
	 * so reading absent as this value puts the whole existing corpus on one
	 * route per case type. The per-route uniqueness rule then reduces to the
	 * per-case-type rule it replaces, and no backfill has to run.
	 *
	 * @var string
	 */
	public const VARIANT_DEFAULT = 'standaard';

	/**
	 * Constructor.
	 *
	 * @param WorkflowDefinitionRepository $repository Reads the case type that records the default.
	 * @param LoggerInterface              $logger     Logs an ambiguous pick.
	 */
	public function __construct(
		private readonly WorkflowDefinitionRepository $repository,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the route a definition is on.
	 *
	 * Two definitions on the same case type and the same route are versions of
	 * each other; on different routes they are siblings, and both may be
	 * published at once.
	 *
	 * @param array<string, mixed> $row Definition row.
	 *
	 * @return string The route slug, never empty.
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function variantOf(array $row): string {
		$variant = trim((string)($row['variant'] ?? ''));
		if ($variant === '') {
			return self::VARIANT_DEFAULT;
		}

		return $variant;
	}//end variantOf()

	/**
	 * The definition id a case type records as its default route.
	 *
	 * `caseType.workflowDefinition` is the ONE place a default route is
	 * recorded. There is deliberately no second marker on the definition itself:
	 * two markers can disagree, only one drives behaviour, and the disagreement
	 * is unfindable.
	 *
	 * @param string $caseTypeId The caseType UUID.
	 *
	 * @return string The definition UUID, or the empty string.
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function definitionIdFor(string $caseTypeId): string {
		if ($caseTypeId === '') {
			return '';
		}

		$caseType = $this->repository->findCaseType(caseTypeId: $caseTypeId);
		if ($caseType === null) {
			return '';
		}

		$pinned = ($caseType['workflowDefinition'] ?? '');
		if (is_array($pinned) === true) {
			return $this->idOf(row: $pinned);
		}

		return (string)$pinned;
	}//end definitionIdFor()

	/**
	 * Pick a case type's DEFAULT route among its active definitions.
	 *
	 * One candidate needs no default and raises no ambiguity, which is every
	 * case type carrying one route. Several candidates are answered by the
	 * recorded default. Several candidates and no usable default is a
	 * misconfiguration: the pick is deterministic, by route slug then by highest
	 * version, and it is logged. Answering that state randomly is how a case
	 * ends up on a route nobody chose for it.
	 *
	 * @param array<int, array<string, mixed>> $active     The active definitions, at least one.
	 * @param string                           $caseTypeId The caseType UUID.
	 *
	 * @return array<string, mixed> The chosen definition.
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function among(array $active, string $caseTypeId): array {
		if (count($active) === 1) {
			return $active[0];
		}

		$pinned = $this->definitionIdFor(caseTypeId: $caseTypeId);
		foreach ($active as $candidate) {
			if ($pinned !== '' && $this->idOf(row: $candidate) === $pinned) {
				return $candidate;
			}
		}

		usort($active, fn (array $left, array $right): int => ($this->routeOrder(row: $left)
			<=> $this->routeOrder(row: $right)));

		$this->logger->warning(
			'Dossiq: case type has several active workflow definitions and no usable default route',
			[
				'app' => AppIdentity::APP_ID,
				'caseType' => $caseTypeId,
				'routes' => array_map(fn (array $row): string => $this->variantOf(row: $row), $active),
				'chose' => $this->idOf(row: $active[0]),
			]
		);

		return $active[0];
	}//end among()

	/**
	 * The sort key that makes an ambiguous pick reproducible: route slug first,
	 * then the highest version of that route.
	 *
	 * @param array<string, mixed> $row Definition row.
	 *
	 * @return array{0: string, 1: int} The sort key.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function routeOrder(array $row): array {
		return [$this->variantOf(row: $row), -(int)($row['version'] ?? 0)];
	}//end routeOrder()

	/**
	 * The uuid of a row, whichever of the two keys OpenRegister answered with.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or the empty string.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['uuid'] ?? ''));
	}//end idOf()
}//end class
