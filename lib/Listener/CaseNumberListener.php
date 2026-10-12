<?php

/**
 * Dossiq case-number listener.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\CaseNumberService;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Backfills the case number when the register did not generate one.
 *
 * A pure observer (ADR-022): every rule about what a number looks like lives
 * in {@see CaseNumberService}, and the service is a no-op on an instance whose
 * OpenRegister already filled the field.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseNumberListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param CaseNumberService        $caseNumbers  The case-number service.
	 * @param ObjectSchemaSlugResolver $slugResolver Schema id-to-slug resolver.
	 * @param ReceiptMoment $receipt Tells a received case from a draft (decision 180).
	 */
	public function __construct(
		private readonly CaseNumberService $caseNumbers,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly ReceiptMoment $receipt = new ReceiptMoment(),
	) {
	}//end __construct()

	/**
	 * Handle a case-created event.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function handle(Event $event): void {
		// A draft is not a case yet and gets no number; the moment it is sent
		// is when it is received, and numbered (decision 201).
		$payload = $this->receipt->receivedObject(event: $event, for: self::class);
		if ($payload === null) {
			return;
		}

		// The payload carries the schema as an ID, never as a slug — reading
		// `@self.schemaSlug` answers an empty string for every object, which is
		// how the sibling termijn listener spent months never firing. The
		// shared resolver is the one place that converts.
		if ($this->slugResolver->resolveFromPayload(payload: $payload) !== 'case') {
			return;
		}

		$this->caseNumbers->assign(case: $payload);
	}//end handle()
}//end class
