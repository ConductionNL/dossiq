<?php

/**
 * The case an event says was just received, with its case type as a slug.
 *
 * A create of a case that is not a draft, or the moment a draft is sent,
 * answers the case's payload, its id, the case type reference it carries
 * and that case type's slug. A draft, an object of another schema, a case
 * without an id or case type, or a case type that resolves to no slug
 * answers null; the last is logged, because a case that gets no slug gets
 * no term either. {@see \OCA\Dossiq\Listener\DeadlineCaseCreatedListener}
 * binds the terms of what this answers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener\Support
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

namespace OCA\Dossiq\Listener\Support;

use OCA\Dossiq\Kernel\Contracts\CaseTypeSlugResolverInterface;
use OCA\Dossiq\Kernel\Contracts\ObjectSchemaSlugResolverInterface;
use OCA\Dossiq\Listener\CreatedCase;
use OCA\Dossiq\Listener\NewCaseIdentity;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCP\EventDispatcher\Event;
use Psr\Log\LoggerInterface;

/**
 * Reads the received case of an event.
 *
 * @spec openspec/specs/termijn-binding/spec.md
 */
final class ReceivedCaseRead {

	/**
	 * Constructor.
	 *
	 * @param ObjectSchemaSlugResolverInterface $slugResolver  Schema id-to-slug resolver.
	 * @param CaseTypeSlugResolverInterface     $caseTypeSlugs Case-type uuid-to-slug resolver.
	 * @param LoggerInterface                   $logger        Logs a case type that resolves to no slug.
	 * @param ReceiptMoment                     $receipt       Tells a receipt from a draft save: a draft
	 *                                                         is not received, and sending it is (decision 180).
	 */
	public function __construct(
		private readonly ObjectSchemaSlugResolverInterface $slugResolver,
		private readonly CaseTypeSlugResolverInterface $caseTypeSlugs,
		private readonly LoggerInterface $logger,
		private readonly ReceiptMoment $receipt = new ReceiptMoment(),
	) {
	}//end __construct()

	/**
	 * The received case of the event, or null when the event received none.
	 *
	 * @param Event  $event The event.
	 * @param string $for   The listener that asks, as the receipt moment wants it named.
	 *
	 * @return CreatedCase|null The case's identity and payload.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function read(Event $event, string $for): ?CreatedCase {
		$payload = $this->receipt->receivedObject(event: $event, for: $for);
		if ($payload === null || $this->slugResolver->resolveFromPayload(payload: $payload) !== 'case') {
			return null;
		}

		$identity = $this->identity(payload: $payload);
		if ($identity === null) {
			return null;
		}

		return new CreatedCase(identity: $identity, payload: $payload);
	}//end read()

	/**
	 * The case's id, case type reference and case type slug.
	 *
	 * @param array<string,mixed> $payload The case.
	 *
	 * @return NewCaseIdentity|null Null without an id or case type, or when the case type has no slug.
	 */
	private function identity(array $payload): ?NewCaseIdentity {
		$caseId      = (string)($payload['id'] ?? ($payload['uuid'] ?? ''));
		$caseTypeRef = (string)($payload['caseType'] ?? '');
		if ($caseId === '' || $caseTypeRef === '') {
			return null;
		}

		// 🔴 A `case` carries its case type as a UUID; a deadlineDefinition
		// binds by SLUG. Handing the uuid straight to TermijnService matched
		// no shipped case type, so no term was ever bound and no FlowTimer
		// ever armed — and the refusal was invisible at the default loglevel.
		$caseType = $this->caseTypeSlugs->toSlug(reference: $caseTypeRef);
		if ($caseType === '') {
			$this->logger->warning(
				'Dossiq termijn: the case type behind a new case could not be resolved to a slug, '
				. 'so no statutory term was started',
				['case' => $caseId, 'caseType' => $caseTypeRef]
			);

			return null;
		}

		return new NewCaseIdentity(caseId: $caseId, caseTypeRef: $caseTypeRef, caseType: $caseType);
	}//end identity()
}//end class
