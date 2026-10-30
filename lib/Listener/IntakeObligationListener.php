<?php

/**
 * Places the obligations a form asked for when the case is received.
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
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Obligations\IntakeObligations;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * On a received case, places an obligation per `intakeObligations` entry.
 *
 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them
 *
 * @template-implements IEventListener<Event>
 */
class IntakeObligationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ReceiptMoment            $receipt      Tells a receipt from a draft save, once per case.
	 * @param ObjectSchemaSlugResolver $slugResolver Tells a case from another object.
	 * @param IntakeObligations        $obligations  Places the obligations.
	 */
	public function __construct(
		private readonly ReceiptMoment $receipt,
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly IntakeObligations $obligations,
	) {
	}//end __construct()

	/**
	 * Handle a created object, or the activation that sends a draft.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @listener-placement inline correctness — the case must wait from the
	 * moment it exists: a deferred placing leaves a window in which a handler
	 * can close a case that should be waiting. The work is a read per entry
	 * and one write per obligation, at most once per case.
	 *
	 * @spec openspec/changes/obligations-placed-at-intake/specs/obligations-placed-at-intake/spec.md#requirement-a-case-opened-with-intake-obligations-waits-on-them
	 */
	public function handle(Event $event): void {
		$case = $this->receipt->receivedObject(event: $event, for: self::class);
		if ($case === null || empty($case[IntakeObligations::FIELD]) === true) {
			return;
		}

		if ($this->slugResolver->resolveFromPayload(payload: $case) !== 'case') {
			return;
		}

		$this->obligations->placeFor(case: $case);
	}//end handle()
}//end class
