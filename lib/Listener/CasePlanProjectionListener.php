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

use OCA\Dossiq\Kernel\Contracts\CasePlanProjectionServiceInterface;
use OCA\Dossiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
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
	 * @param CasePlanProjectionServiceInterface $projection   The caseModel-to-plan projection.
	 * @param ObjectSchemaSlugResolverInterface  $slugResolver Schema id-to-slug resolver.
	 * @param LoggerInterface           $logger       Logger.
	 * @param ReceiptMoment $receipt Tells a received case from a draft (decision 180).
	 */
	public function __construct(
		private readonly CasePlanProjectionServiceInterface $projection,
		private readonly ObjectSchemaSlugResolverInterface $slugResolver,
		private readonly LoggerInterface $logger,
		private readonly ReceiptMoment $receipt = new ReceiptMoment(),
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
		// A draft has no plan; the plan is projected when the draft is sent.
		$payload = $this->receipt->receivedObject(event: $event, for: self::class);
		if ($payload === null || $this->slugResolver->resolveFromPayload(payload: $payload) !== 'case') {
			return;
		}

		[$caseId, $caseTypeId] = $this->caseAndType(payload: $payload);
		if ($caseId === '' || $caseTypeId === '') {
			return;
		}

		$result = $this->project(caseId: $caseId, caseTypeId: $caseTypeId);
		if ($result === null) {
			return;
		}

		$this->reportUnplanned(caseId: $caseId, caseTypeId: $caseTypeId, result: $result);
	}//end handle()

	/**
	 * The case id and case type id of a created case.
	 *
	 * @param array<string, mixed> $payload The created case.
	 *
	 * @return array{0: string, 1: string} The case id and the case type id, '' when absent.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	private function caseAndType(array $payload): array {
		$caseId = (string)($payload['id'] ?? ($payload['uuid'] ?? ''));
		$caseTypeId = (string)($payload['caseType'] ?? '');

		return [$caseId, $caseTypeId];
	}//end caseAndType()

	/**
	 * Project the case type's caseModel onto the new case, or null (logged) when that fails.
	 *
	 * @param string $caseId     The new case.
	 * @param string $caseTypeId Its case type.
	 *
	 * @return array<string, mixed>|null The projection result (`projected`, `reason`), or null.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	private function project(string $caseId, string $caseTypeId): ?array {
		try {
			return $this->projection->projectForCase(caseId: $caseId, caseTypeId: $caseTypeId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq case plan: projecting the caseModel of a new case failed',
				['case' => $caseId, 'caseType' => $caseTypeId, 'exception' => $e->getMessage()]
			);

			return null;
		}
	}//end project()

	/**
	 * Warn about a CMMN case that started without a plan.
	 *
	 * @param string               $caseId     The new case.
	 * @param string               $caseTypeId Its case type.
	 * @param array<string, mixed> $result     The projection result.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	private function reportUnplanned(string $caseId, string $caseTypeId, array $result): void {
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
	}//end reportUnplanned()
}//end class
