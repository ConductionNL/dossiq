<?php

/**
 * Dossiq case marker derivation listener.
 *
 * REQ-MRK-02 and REQ-MRK-03: the top-level mirror of the assessed risk level,
 * and the set of markers standing on the case, are DERIVED on every save.
 *
 * WHY THIS IS PRE-PERSIST RATHER THAN POST. ADR-078 asks that raising a marker
 * never slow the write that caused it, and the way to spend nothing is to
 * write nothing extra: both fields are computed into the save that is already
 * happening, through `setModifiedData`, exactly as the priority derivation
 * beside it does. A post-persist listener would be a SECOND write charged to
 * the same request, which is the latency ADR-078 exists to prevent, and dossiq
 * has no deferral service to hand it to.
 *
 * NOTHING HERE IS A JUDGEMENT A PERSON CAN OVERRULE. `riskLevel` mirrors the
 * assessment so a facet can be taken over it; `attentionMarkers` is whichever
 * declared conditions are true right now. A marker whose condition stopped
 * being true is absent from the next answer, so handling the work clears it
 * and nobody had to dismiss it. That is the whole difference from the per-user
 * unread badge, which clears because somebody looked.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\CaseAttentionMarkerService;
use OCA\Dossiq\Service\CaseRiskAssessmentService;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derive the risk mirror and the marker set of a case about to be written.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */
class CaseMarkerDerivationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SettingsService            $settingsService Schema slug bridge.
	 * @param CaseRiskAssessmentService  $riskService     The assessed level and what it feeds.
	 * @param CaseAttentionMarkerService $markerService   What raises a marker and what clears it.
	 * @param LoggerInterface            $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseRiskAssessmentService $riskService,
		private readonly CaseAttentionMarkerService $markerService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Derive the marker fields of a case about to be written.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->apply(event: $event, entity: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->apply(event: $event, entity: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Write the derived block onto the case being saved.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-persist event.
	 * @param ObjectEntity                            $entity The case being written.
	 *
	 * @return void
	 */
	private function apply(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity): void {
		$payload = $this->payload(entity: $entity);
		if ($payload === null || $this->isCaseSchema(object: $payload) === false) {
			return;
		}

		$modified = $event->getModifiedData();
		$payload = array_merge($payload, $modified);

		$event->setModifiedData(
			array_merge(
				$modified,
				$this->riskService->resolve(case: $payload),
				$this->markerService->resolve(case: $payload)
			)
		);
	}//end apply()

	/**
	 * Read an entity's payload, or null when it cannot be read.
	 *
	 * @param ObjectEntity $entity The entity carried by the event.
	 *
	 * @return array<string, mixed>|null The payload, or null.
	 */
	private function payload(ObjectEntity $entity): ?array {
		try {
			return $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: marker derivation could not read the payload: ' . $e->getMessage()
			);
			return null;
		}
	}//end payload()

	/**
	 * Whether the supplied payload belongs to the `case` schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return boolean True when this is a case.
	 */
	private function isCaseSchema(array $object): bool {
		$expected = $this->settingsService->getConfigValue('case_schema');
		if ($expected === '') {
			return false;
		}

		$candidate = (string)($object['@self']['schema'] ?? ($object['schema'] ?? ''));

		return $candidate !== '' && (
			$candidate === $expected
			|| str_ends_with($candidate, '/' . $expected)
		);
	}//end isCaseSchema()
}//end class
