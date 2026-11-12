<?php

/**
 * Dossiq Bezwaar Advice Requested Listener.
 *
 * Watches OpenRegister `bezwaar` (lifecycle) object updates for the
 * status transition to "Hearing planned" (the canonical state where
 * the bezwaar is formally referred to an advisory committee for the
 * hearing+advice track) and triggers the auto-assignment of the default
 * bezwaaradviescommissie via AdvisoryCommitteeService. The bezwaar
 * lifecycle states are owned by the sister bezwaar-lifecycle capability.
 *
 * The listener intentionally swallows infrastructure errors: failure to
 * auto-assign SHALL NOT block the parent bezwaar transition; operators
 * fall back to manual assignment via the BAC index page.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Bezwaar\AdvisoryCommitteeService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Listener\Support\ActsOnStatusEntry;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Auto-assigns the default BAC when bezwaar enters "Hearing planned".
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bezwaar-advisory-committee/spec.md
 */
class BezwaarAdviceRequestedListener implements IEventListener {
	use ActsOnStatusEntry;

	private const TRIGGER_STATUS = 'Hearing planned';

	/**
	 * The objection schema's config key, and the status this listener acts on.
	 */
	private const ENTRY = ['schemaKey' => 'bezwaar_schema', 'status' => self::TRIGGER_STATUS];

	/**
	 * What a swallowed failure is logged as.
	 */
	private const FAILURE_LOG = 'Dossiq BAC: advice-requested listener swallowed exception: ';

	/**
	 * Constructor.
	 *
	 * @param AdvisoryCommitteeService $bacService The BAC service
	 * @param SettingsService $settingsService Schema slug bridge
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly AdvisoryCommitteeService $bacService,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a case update event.
	 *
	 * Filters: must be a `case` schema object, must have transitioned to
	 * the trigger status (`status` changed in the update payload).
	 *
	 * @param Event $event The dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handle(Event $event): void {
		$this->actOnStatusEntry(
			event: $event,
			act: fn (string $objectionId): ?array => $this->bacService->autoAssignDefaultCommittee(objectionId: $objectionId)
		);
	}//end handle()
}//end class
