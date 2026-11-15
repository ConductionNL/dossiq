<?php

/**
 * Dossiq Bezwaar Hearing Scheduled Listener.
 *
 * Watches OpenRegister `bezwaar` (lifecycle) object updates for the
 * status transition to "Hearing planned" and optionally seeds a
 * default hearingSession via HearingService::seedDefaultHearing(). The
 * canonical hearing lifecycle states are owned by the bezwaar-hearing
 * capability; this listener does not own any transition logic.
 *
 * The listener intentionally swallows infrastructure errors: failure to
 * seed a default hearing SHALL NOT block the parent bezwaar transition.
 * Operators fall back to manual scheduling via the BezwaarHearings index
 * page.
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

use OCA\Dossiq\Kernel\Contracts\HearingServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\Support\ActsOnStatusEntry;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Seeds a default hearingSession when bezwaar status becomes
 * "Hearing planned".
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/bezwaar-hearing/spec.md
 */
class BezwaarHearingScheduledListener implements IEventListener {
	use ActsOnStatusEntry;

	private const TRIGGER_STATUS = 'Hearing planned';

	/**
	 * The objection schema's config key, and the status this listener acts on.
	 */
	private const ENTRY = ['schemaKey' => 'bezwaar_schema', 'status' => self::TRIGGER_STATUS];

	/**
	 * What a swallowed failure is logged as.
	 */
	private const FAILURE_LOG = 'Dossiq hearing: scheduled listener swallowed exception: ';

	/**
	 * Constructor.
	 *
	 * @param HearingServiceInterface $hearingService Hearing domain service
	 * @param SettingsServiceInterface $settingsService Schema slug bridge
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly HearingServiceInterface $hearingService,
		private readonly SettingsServiceInterface $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle a bezwaar update event.
	 *
	 * @param Event $event Dispatched event
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handle(Event $event): void {
		$this->actOnStatusEntry(
			event: $event,
			act: fn (string $objectionId): ?array => $this->hearingService->seedDefaultHearing(objectionId: $objectionId)
		);
	}//end handle()
}//end class
