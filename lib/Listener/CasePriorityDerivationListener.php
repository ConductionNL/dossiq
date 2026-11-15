<?php

/**
 * Dossiq case priority derivation listener.
 *
 * REQ-PRI-01 to REQ-PRI-03: a case stores impact and urgency, its priority is
 * the answer its case type's matrix gives for the pair, and a person may
 * override that answer on the record.
 *
 * This runs on OpenRegister's PRE-persist events, so the derived priority is
 * written in the same save as the impact or urgency that changed it. A
 * post-persist derivation would be a second write, and a case would sit at the
 * wrong priority for however long that took.
 *
 * THE OVERRIDE IS STAMPED HERE, not typed. `priorityOverride` and
 * `priorityOverrideReason` are the two fields a person edits; who set it and
 * when are facts about the edit, so they are written from the session rather
 * than offered as fields somebody could fill in with anybody's name. Clearing
 * the override clears all three, which is what makes clearing return to the
 * derived answer rather than to a stale byline.
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
 * @spec openspec/specs/case-priority/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\Contracts\CasePriorityServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Listener\Support\PreSaveCheck;
use OCA\Dossiq\Listener\Support\ReadsCaseWrites;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Derive a case's priority, and record an override as a fact.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/case-priority/spec.md
 */
class CasePriorityDerivationListener implements IEventListener {
	use ReadsCaseWrites;

	/**
	 * The check named in the log when a payload cannot be read.
	 */
	private const PAYLOAD_CHECK = 'priority derivation';

	/**
	 * The bookkeeping an override carries, cleared together with it.
	 *
	 * @var array<int, string>
	 */
	private const OVERRIDE_FIELDS = [
		'priorityOverrideBy',
		'priorityOverrideAt',
		'priorityOverrideReason',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface     $settingsService Schema slug bridge.
	 * @param CasePriorityServiceInterface $priorityService Where a priority comes from.
	 * @param IUserSession        $userSession     Who is making this edit.
	 * @param LoggerInterface     $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly CasePriorityServiceInterface $priorityService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Derive the priority of a case about to be written.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-priority/spec.md
	 */
	public function handle(Event $event): void {
		(new PreSaveCheck(logger: $this->logger))->inspectWithPrevious(event: $event, inspect: $this->apply(...));
	}//end handle()

	/**
	 * Write the priority block onto the case being saved.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event    The pre-persist event.
	 * @param ObjectEntity                            $entity   The case being written.
	 * @param ObjectEntity|null                       $previous The case as it was.
	 *
	 * @return void
	 */
	private function apply(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity, ?ObjectEntity $previous): void {
		$payload = $this->caseAsSaved(event: $event, entity: $entity);
		if ($payload === null) {
			return;
		}

		$modified = $event->getModifiedData();

		$stamp = $this->overrideStamp(payload: $payload, previous: $this->previousPayload(previous: $previous));
		$payload = array_merge($payload, $stamp);

		$derived = $this->priorityService->resolve(case: $payload);
		$event->setModifiedData(array_merge($modified, $stamp, $derived));
	}//end apply()

	/**
	 * The case as it was, or null on create.
	 *
	 * @param ObjectEntity|null $previous The case as it was.
	 *
	 * @return array<string, mixed>|null The payload, or null.
	 */
	private function previousPayload(?ObjectEntity $previous): ?array {
		$was = null;
		if ($previous !== null) {
			$was = $this->payload(entity: $previous);
		}

		return $was;
	}//end previousPayload()

	/**
	 * The override bookkeeping this save implies.
	 *
	 * An override that was not there before, or that changed value, is stamped
	 * with the person and the moment. An override that has gone takes its
	 * byline and its reason with it. An override that is unchanged is left
	 * exactly as it is, so re-saving a case does not reassign somebody else's
	 * decision to whoever touched it last.
	 *
	 * @param array<string, mixed>      $payload  The case as it is being saved.
	 * @param array<string, mixed>|null $previous The case as it was, or null on create.
	 *
	 * @return array<string, mixed> The fields to write, possibly empty.
	 */
	private function overrideStamp(array $payload, ?array $previous): array {
		$override = trim((string)($payload['priorityOverride'] ?? ''));
		$before = trim((string)(($previous ?? [])['priorityOverride'] ?? ''));

		if ($override === $before) {
			return [];
		}

		if ($override === '') {
			return array_fill_keys(self::OVERRIDE_FIELDS, null);
		}

		return [
			'priorityOverrideBy' => $this->actor(),
			'priorityOverrideAt' => (new DateTimeImmutable())->format('c'),
		];
	}//end overrideStamp()

	/**
	 * Who is making this edit.
	 *
	 * @return string The user id, or `system` for a background write.
	 */
	private function actor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return 'system';
		}

		return $user->getUID();
	}//end actor()
}//end class
