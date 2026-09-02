<?php

/**
 * Starts the flow run that drives a voorstel's parafering, when one exists.
 *
 * 🔴 IT ONLY STARTS A RUN FOR AN ENABLED FLOW, AND THAT IS THE WHOLE SWITCH.
 *
 * EndorsementRouteFlowMigrator projects every approval route onto a flow and
 * ships it `enabled: false`, because the route still drives parafering and a
 * projection running alongside it would ask every approver twice. Enabling one
 * flow is therefore the act that moves ONE route onto the engine, and this
 * gateway is what reads that decision.
 *
 * The consequence worth stating: a voorstel that started before its route was
 * enabled carries a `routeSnapshot` and no `flowRunId`, and finishes the way it
 * started. A hard cutover would strand whatever is mid-parafering, and the dev
 * instance cannot show that because it holds zero voorstellen. Production can.
 *
 * Resolved by MARKER rather than by name, like the projection that wrote it: a
 * flow's name is editable and a renamed flow would otherwise look like a
 * missing one, silently putting every new voorstel back on the old path.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Parafeer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Parafeer;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves and starts the projected flow for an approval route.
 *
 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
 */
class ParaferingFlowGateway {

	/**
	 * OpenRegister's FlowService, resolved by name so dossiq does not hard-depend on it.
	 *
	 * @var string
	 */
	private const FLOW_SERVICE = 'OCA\\OpenRegister\\Service\\Flow\\FlowService';

	/**
	 * The provenance marker EndorsementRouteFlowMigrator writes into a flow's notes.
	 *
	 * @var string
	 */
	private const MARKER_PREFIX = 'dossiq:endorsementRoute:';

	/**
	 * Page size when walking this app's flows.
	 *
	 * @var integer
	 */
	private const FLOW_PAGE = 100;

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container DI container, resolves OpenRegister's FlowService.
	 * @param LoggerInterface    $logger    The logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Start the run for a route's projected flow, when that flow is enabled.
	 *
	 * @param string               $routeId  The approval route's id.
	 * @param string               $subjectId The voorstel the run is about.
	 * @param array<string, mixed> $context  Extra run context.
	 *
	 * @return string The run id, or an empty string when no enabled flow exists.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function startForRoute(string $routeId, string $subjectId, array $context=[]): string {
		$routeId = trim($routeId);
		if ($routeId === '') {
			return '';
		}

		$flowService = $this->flowService();
		if ($flowService === null) {
			return '';
		}

		$flowUuid = $this->enabledFlowFor(flowService: $flowService, routeId: $routeId);
		if ($flowUuid === '') {
			// The ordinary case today: the projection exists but is disabled,
			// so the route still drives parafering. Not a failure, and not
			// logged as one.
			return '';
		}

		try {
			$run = $flowService->run(
				$flowUuid,
				['id' => $subjectId],
				$context,
				false,
				'manual'
			);
		} catch (Throwable $e) {
			// 🔴 Reported, never rethrown. Activation must not fail because the
			// engine did: a voorstel that cannot start a run is a voorstel that
			// takes the old path, which is exactly what the dual path is for.
			$this->logger->error(
				'Dossiq: could not start the parafering flow run for route ' . $routeId,
				['voorstel' => $subjectId, 'flow' => $flowUuid, 'exception' => $e->getMessage()],
			);
			return '';
		}

		$runId = '';
		if (is_object($run) === true && method_exists($run, 'getUuid') === true) {
			$runId = (string)$run->getUuid();
		}

		if ($runId === '') {
			$this->logger->error(
				'Dossiq: the parafering flow run started but reported no id, so the voorstel cannot reference it',
				['voorstel' => $subjectId, 'flow' => $flowUuid],
			);
			return '';
		}

		$this->logger->info(
			'Dossiq: parafering runs as a flow for this voorstel',
			['voorstel' => $subjectId, 'flow' => $flowUuid, 'run' => $runId],
		);

		return $runId;
	}//end startForRoute()

	/**
	 * The uuid of the ENABLED flow projected from this route, if there is one.
	 *
	 * @param object $flowService OpenRegister's FlowService.
	 * @param string $routeId     The approval route's id.
	 *
	 * @return string The flow uuid, or an empty string.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	private function enabledFlowFor(object $flowService, string $routeId): string {
		$marker = (self::MARKER_PREFIX . $routeId);
		$offset = 0;

		while (true) {
			try {
				$page = $flowService->findAll('dossiq', null, null, self::FLOW_PAGE, $offset);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Dossiq: could not list flows while looking for a projected approval route',
					['route' => $routeId, 'exception' => $e->getMessage()],
				);
				return '';
			}

			if (is_array($page) === false || $page === []) {
				return '';
			}

			foreach ($page as $flow) {
				if ($this->markerOf(flow: $flow) !== $marker) {
					continue;
				}

				if ($this->isEnabled(flow: $flow) === false) {
					// Found, and deliberately not running. The route drives
					// parafering until somebody enables this.
					return '';
				}

				return $this->uuidOf(flow: $flow);
			}

			if (count($page) < self::FLOW_PAGE) {
				return '';
			}

			$offset += self::FLOW_PAGE;
		}//end while
	}//end enabledFlowFor()

	/**
	 * The provenance marker a flow carries, if any.
	 *
	 * @param mixed $flow The flow.
	 *
	 * @return string The marker.
	 */
	private function markerOf(mixed $flow): string {
		if (is_object($flow) === false || method_exists($flow, 'getNotes') === false) {
			return '';
		}

		return trim((string)($flow->getNotes() ?? ''));
	}//end markerOf()

	/**
	 * Whether a flow is enabled.
	 *
	 * Defaults to FALSE when the flow cannot say. An unreadable enabled-flag is
	 * not permission to run: the projections ship disabled, and treating
	 * "cannot tell" as "yes" would start runs alongside the route that still
	 * drives parafering, asking every approver twice.
	 *
	 * @param mixed $flow The flow.
	 *
	 * @return boolean True when enabled.
	 */
	private function isEnabled(mixed $flow): bool {
		if (is_object($flow) === false || method_exists($flow, 'getEnabled') === false) {
			return false;
		}

		return ($flow->getEnabled() === true);
	}//end isEnabled()

	/**
	 * A flow's uuid.
	 *
	 * @param mixed $flow The flow.
	 *
	 * @return string The uuid.
	 */
	private function uuidOf(mixed $flow): string {
		if (is_object($flow) === false || method_exists($flow, 'getUuid') === false) {
			return '';
		}

		return (string)$flow->getUuid();
	}//end uuidOf()

	/**
	 * OpenRegister's FlowService, or null when the app is absent.
	 *
	 * @return object|null The flow service.
	 */
	private function flowService(): ?object {
		try {
			return $this->container->get(self::FLOW_SERVICE);
		} catch (Throwable $e) {
			return null;
		}
	}//end flowService()
}//end class
