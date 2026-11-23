<?php

/**
 * Dossiq: a new case carries its last activity from the start.
 *
 * Runs on OpenRegister's PRE-persist create event and writes `lastActivityAt`
 * into the same save that creates the case. CaseLastActivityListener stamps a
 * created case only when that field is empty, so the second full save it used
 * to make right after every create (about 1.1 s on the demo stack) is gone.
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
 * @spec openspec/specs/case-last-activity-at/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use DateTimeInterface;
use OCA\Dossiq\Kernel\Contracts\CaseActivityStampInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\Support\PreSaveCheck;
use OCA\Dossiq\Listener\Support\SchemaMembership;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Stamps `lastActivityAt` on a case while it is being created.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/case-last-activity-at/spec.md
 */
class CaseCreationActivityStampListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Names the case schema.
	 * @param ITimeFactory             $time            The clock.
	 * @param LoggerInterface          $logger          Receives an unreadable payload.
	 *
	 * @spec openspec/specs/case-last-activity-at/spec.md
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Hand a pre-persist create to the stamp; any other event is not this listener's.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-last-activity-at/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->stampIfMissing(creating: $event);
		}
	}//end handle()

	/**
	 * Add `lastActivityAt` to a case being created, unless it already has one.
	 *
	 * @param ObjectCreatingEvent $creating The pre-persist create event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-last-activity-at/spec.md
	 */
	private function stampIfMissing(ObjectCreatingEvent $creating): void {
		$case = $this->caseBeingCreated(event: $creating);
		if ($case === null || trim((string)($case[CaseActivityStampInterface::FIELD] ?? '')) !== '') {
			return;
		}

		$creating->setModifiedData(
			array_merge(
				$creating->getModifiedData(),
				[CaseActivityStampInterface::FIELD => $this->time->now()->format(DateTimeInterface::ATOM)]
			)
		);
	}//end stampIfMissing()

	/**
	 * The case as the create will store it, or null when the object is no case or cannot be read.
	 *
	 * @param ObjectCreatingEvent $event The pre-persist create event.
	 *
	 * @return array<string, mixed>|null The payload with the modified data on top.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SchemaMembership::belongsTo() is a pure predicate on the payload.
	 *
	 * @spec openspec/specs/case-last-activity-at/spec.md
	 */
	private function caseBeingCreated(ObjectCreatingEvent $event): ?array {
		$payload = (new PreSaveCheck(logger: $this->logger))->read(entity: $event->getObject(), check: 'activity stamp on creation');
		if ($payload === null
			|| SchemaMembership::belongsTo(object: $payload, expected: $this->settingsService->getConfigValue('case_schema')) === false
		) {
			return null;
		}

		return array_merge($payload, $event->getModifiedData());
	}//end caseBeingCreated()
}//end class
