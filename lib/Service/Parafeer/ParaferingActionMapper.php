<?php

/**
 * Dossiq ParaferingActionMapper.
 *
 * Pure shaping of parafering action data. Split out of ParafeerActieService so
 * that service keeps only the orchestration (authorize, persist, propagate,
 * advance): normalizing the untrusted request payload into the five action
 * inputs, assembling the parafeeractie object payload, and locating the next
 * step in a voorstel's route snapshot live here and nowhere else. Every method
 * is side-effect free — no ObjectService, no logging, no events.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Parafeer
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
 * @spec openspec/specs/parafering-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Parafeer;

/**
 * Shapes parafering action input, payloads and route navigation.
 *
 * Per ADR-005 the request body is NEVER trusted for actor identity: this
 * mapper normalizes the payload only, the acting user id is supplied by the
 * caller from IUserSession.
 *
 * @spec openspec/specs/parafering-actions/spec.md
 *
 * @psalm-suppress UnusedClass
 */
class ParaferingActionMapper {
	/**
	 * Normalize the request payload into the five action inputs.
	 *
	 * @param array<string, mixed> $data Request payload (action, comment, advice, onBehalfOf, mandate).
	 *
	 * @return array<string, mixed> {action, comment, advice, onBehalfOf, mandate}
	 *
	 * @spec openspec/specs/parafering-actions/spec.md
	 */
	public function parseActionInput(array $data): array {
		$onBehalfOf = null;
		if (isset($data['onBehalfOf']) === true && $data['onBehalfOf'] !== '') {
			$onBehalfOf = (string)$data['onBehalfOf'];
		}

		$mandate = null;
		if (isset($data['mandate']) === true && $data['mandate'] !== '') {
			$mandate = (string)$data['mandate'];
		}

		return [
			'action' => (string)($data['action'] ?? ''),
			'comment' => trim((string)($data['comment'] ?? '')),
			'advice' => trim((string)($data['advice'] ?? '')),
			'onBehalfOf' => $onBehalfOf,
			'mandate' => $mandate,
		];
	}//end parseActionInput()

	/**
	 * Build the parafeeractie payload, omitting the optional fields that are unset.
	 *
	 * @param string $proposalId The voorstel UUID.
	 * @param int $stepOrder The step order this action applies to.
	 * @param string $actor The acting user id (from IUserSession, never the body).
	 * @param array<string, mixed> $input The parsed action inputs.
	 *
	 * @return array<string, mixed> The parafeeractie object payload.
	 *
	 * @spec openspec/specs/parafering-actions/spec.md
	 */
	public function buildActieData(string $proposalId, int $stepOrder, string $actor, array $input): array {
		$actionData = [
			'proposal' => $proposalId,
			'step' => $stepOrder,
			'actor' => $actor,
			'actorType' => 'user',
			'action' => (string)$input['action'],
		];

		if ($input['onBehalfOf'] !== null) {
			$actionData['actorType'] = 'delegate';
			$actionData['onBehalfOf'] = $input['onBehalfOf'];
		}

		if ($input['mandate'] !== null) {
			$actionData['mandate'] = $input['mandate'];
		}

		if ($input['comment'] !== '') {
			$actionData['comment'] = $input['comment'];
		}

		if ($input['advice'] !== '') {
			$actionData['advice'] = $input['advice'];
		}

		return $actionData;
	}//end buildActieData()

	/**
	 * Find the lowest-ordered route step after the current one.
	 *
	 * Accepts the raw routeSnapshot (JSON string or array) and normalizes it.
	 *
	 * @param mixed $snapshotRaw The raw routeSnapshot value.
	 * @param int $currentStep The current step order.
	 *
	 * @return array<string, mixed>|null {order, type}, or null when the route is finished.
	 *
	 * @spec openspec/specs/parafering-actions/spec.md
	 */
	public function findNextRouteStep(mixed $snapshotRaw, int $currentStep): ?array {
		$steps = $snapshotRaw;
		if (is_string($snapshotRaw) === true) {
			$steps = json_decode($snapshotRaw, true);
		}

		if (is_array($steps) === false) {
			$steps = [];
		}

		$nextStep = null;
		$nextStepType = null;
		foreach ($steps as $step) {
			if (is_array($step) === false) {
				continue;
			}

			$order = (int)($step['order'] ?? 0);
			if ($order > $currentStep && ($nextStep === null || $order < $nextStep)) {
				$nextStep = $order;
				$nextStepType = (string)($step['type'] ?? '');
			}
		}

		if ($nextStep === null) {
			return null;
		}

		return [
			'order' => $nextStep,
			'type' => $nextStepType,
		];
	}//end findNextRouteStep()
}//end class
