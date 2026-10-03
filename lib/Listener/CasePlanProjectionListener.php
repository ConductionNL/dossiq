<?php

/**
 * Project a CMMN case's plan onto OpenRegister at case start.
 *
 * Observes OpenRegister's `ObjectCreatedEvent` on the dossiq `case` schema and
 * hands the case to {@see CasePlanProjectionService}. A pure observer
 * (ADR-022): it decides nothing, not even whether the caseType is
 * CMMN-managed, because that answer belongs beside the conversion tables that
 * depend on it.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\CasePlanProjectionService;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Creates the OpenRegister case plan for a newly created case.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
class CasePlanProjectionListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param CasePlanProjectionService $projection   The caseModel-to-plan projection.
	 * @param ObjectSchemaSlugResolver  $slugResolver Schema id-to-slug resolver.
	 * @param LoggerInterface           $logger       Logger.
	 */
	public function __construct(
		private readonly CasePlanProjectionService $projection,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a case-created event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatedEvent) === false) {
			return;
		}

		$payload = $this->extractObject(event: $event);
		if ($payload === null || $this->slugResolver->resolveFromPayload(payload: $payload) !== 'case') {
			return;
		}

		$caseId = (string)($payload['id'] ?? ($payload['uuid'] ?? ''));
		$caseTypeId = (string)($payload['caseType'] ?? '');
		if ($caseId === '' || $caseTypeId === '') {
			return;
		}

		try {
			$result = $this->projection->projectForCase(caseId: $caseId, caseTypeId: $caseTypeId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq case plan: projecting the caseModel of a new case failed',
				['case' => $caseId, 'caseType' => $caseTypeId, 'exception' => $e->getMessage()]
			);

			return;
		}

		if ($result['projected'] === true || $result['reason'] === 'case_not_cmmn_managed') {
			return;
		}

		// NOT debug. A CMMN case that started without a plan has no adaptive
		// surface at all, and the panel would render an empty plan rather than
		// an error. Every reason other than "this is a BPMN case" is a state
		// somebody has to see.
		$this->logger->warning(
			'Dossiq case plan: a new case started without an OpenRegister case plan',
			['case' => $caseId, 'caseType' => $caseTypeId, 'reason' => $result['reason']]
		);
	}//end handle()

	/**
	 * Extract the OpenRegister object payload from an event.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return array<string, mixed>|null The payload, or null when it carries none.
	 */
	private function extractObject(Event $event): ?array {
		if (method_exists($event, 'getObject') === false) {
			return null;
		}

		$object = $event->getObject();
		if (is_array($object) === true) {
			return $object;
		}

		if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
			$serialized = $object->jsonSerialize();
			if (is_array($serialized) === true) {
				return $serialized;
			}
		}

		return null;
	}//end extractObject()
}//end class
