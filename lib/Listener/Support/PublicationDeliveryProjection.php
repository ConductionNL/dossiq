<?php

/**
 * Dossiq Publication Delivery Projection.
 *
 * What `DeliveryConcludedListener` writes once it has told a concluded
 * delivery is a terminal one this app raised: it loads the delivery's case,
 * finds the publication whose delivery carries the correlation id, records
 * the terminal outcome on it and saves the case. Re-delivering a state that
 * is already recorded writes nothing.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;

/**
 * Records a terminal delivery outcome on the matching publication of its case.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class PublicationDeliveryProjection {
	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Resolves the ObjectService + register config.
	 * @param LoggerInterface          $logger          Logger.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the terminal delivery status onto the case's publication record.
	 *
	 * Matches the publication by the delivery correlation id — every
	 * conclusion originates from a dispatched request, which always carried
	 * one. Idempotent: re-delivering the same terminal state is a no-op
	 * write.
	 *
	 * @param ConcludedDelivery $delivery The case, the correlation id, the channel and the terminal outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function project(ConcludedDelivery $delivery): void {
		if ($delivery->caseId === '' || $delivery->correlationId === '') {
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$this->projectWith(objectService: $objectService, delivery: $delivery);
	}//end project()

	/**
	 * Load the delivery's case, apply the outcome to its publication and save it.
	 *
	 * @param object            $objectService The OpenRegister ObjectService.
	 * @param ConcludedDelivery $delivery      The case, the correlation id, the channel and the terminal outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function projectWith(object $objectService, ConcludedDelivery $delivery): void {
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');

		$case = $this->concludedCase(
			found: $objectService->find(id: $delivery->caseId, options: new ObjectFindOptions(register: $register, schema: $schema)),
			delivery: $delivery
		);
		if ($case === null) {
			return;
		}

		$applied = $this->publicationsWith(case: $case, delivery: $delivery);
		if ($applied === null) {
			return;
		}

		$case['publications'] = $applied;
		$objectService->saveObject(object: $case, options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $register, schema: $schema)));
	}//end projectWith()

	/**
	 * The delivery's case as found, logging when it is not found.
	 *
	 * @param mixed             $found    What OpenRegister found for the delivery's case id.
	 * @param ConcludedDelivery $delivery The concluded delivery.
	 *
	 * @return array<string, mixed>|null The case data, or null when not found.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function concludedCase(mixed $found, ConcludedDelivery $delivery): ?array {
		$case = $this->caseOf(obj: $found);
		if ($case === null) {
			$this->logger->warning(
				'Dossiq DeliveryConcludedListener: case not found for concluded delivery',
				['caseId' => $delivery->caseId, 'correlationId' => $delivery->correlationId]
			);
		}

		return $case;
	}//end concludedCase()

	/**
	 * The case's publications with the outcome on the matching delivery, or
	 * null when there is nothing to write.
	 *
	 * Nothing is written when the publications are not a list, when no
	 * publication carries the correlation id (logged), and when the matching
	 * delivery already has this terminal state (idempotent).
	 *
	 * @param array<string, mixed> $case     The case.
	 * @param ConcludedDelivery    $delivery The outcome.
	 *
	 * @return array<int|string, mixed>|null The publications to save.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function publicationsWith(array $case, ConcludedDelivery $delivery): ?array {
		$publications = $case['publications'] ?? [];
		if (is_array($publications) === false) {
			return null;
		}

		$applied = $this->applyOutcome(publications: $publications, delivery: $delivery);
		if ($applied === null) {
			$this->logger->warning(
				'Dossiq DeliveryConcludedListener: no publication matches the concluded delivery',
				['caseId' => $delivery->caseId, 'correlationId' => $delivery->correlationId, 'channel' => $delivery->channel]
			);
			return null;
		}

		if ($applied === []) {
			// Idempotent: this terminal state is already projected.
			return null;
		}

		return $applied;
	}//end publicationsWith()

	/**
	 * A case OpenRegister found, normalised to its array form.
	 *
	 * @param mixed $obj What OpenRegister found, or null.
	 *
	 * @return array<string, mixed>|null The case data, or null when not found.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function caseOf(mixed $obj): ?array {
		if ($obj === null) {
			return null;
		}

		$case = (array)$obj;
		if (is_array($obj) === false && method_exists($obj, 'jsonSerialize') === true) {
			$case = $obj->jsonSerialize();
		}

		return $case;
	}//end caseOf()

	/**
	 * Apply a terminal delivery outcome to the matching publication entry.
	 *
	 * @param array<int, mixed> $publications The case's publications list.
	 * @param ConcludedDelivery $delivery The correlation id to match and the terminal outcome.
	 *
	 * @return array<int, mixed>|null The updated list; an empty array when the
	 *                                terminal state is already projected
	 *                                (idempotent no-op); null when nothing
	 *                                matches.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function applyOutcome(array $publications, ConcludedDelivery $delivery): ?array {
		foreach ($publications as $i => $pub) {
			if (is_array($pub) === false) {
				continue;
			}

			$stored = (array)($pub['delivery'] ?? []);
			if ((string)($stored['correlationId'] ?? '') !== $delivery->correlationId) {
				continue;
			}

			if ((string)($stored['status'] ?? '') === $delivery->outcome['status']) {
				return [];
			}

			$publications[$i]['delivery'] = array_merge($stored, $delivery->outcome);
			return $publications;
		}//end foreach

		return null;
	}//end applyOutcome()
}//end class
