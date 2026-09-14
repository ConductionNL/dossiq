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
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use DateTimeImmutable;
use OCA\Dossiq\Service\CasePriorityService;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derive a case's priority, and record an override as a fact.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */
class CasePriorityDerivationListener implements IEventListener {
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
	 * @param SettingsService     $settingsService Schema slug bridge.
	 * @param CasePriorityService $priorityService Where a priority comes from.
	 * @param IUserSession        $userSession     Who is making this edit.
	 * @param LoggerInterface     $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CasePriorityService $priorityService,
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
	 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->apply(event: $event, entity: $event->getObject(), previous: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->apply(
				event: $event,
				entity: $event->getNewObject(),
				previous: $event->getOldObject()
			);
		}
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
	private function apply(
		ObjectCreatingEvent|ObjectUpdatingEvent $event,
		ObjectEntity $entity,
		?ObjectEntity $previous,
	): void {
		$payload = $this->payload(entity: $entity);
		if ($payload === null || $this->isCaseSchema(object: $payload) === false) {
			return;
		}

		$modified = $event->getModifiedData();
		$payload = array_merge($payload, $modified);

		$stamp = $this->overrideStamp(
			payload: $payload,
			previous: (($previous === null) ? null : $this->payload(entity: $previous))
		);
		$payload = array_merge($payload, $stamp);

		$event->setModifiedData(
			array_merge(
				$modified,
				$stamp,
				$this->priorityService->resolve(case: $payload)
			)
		);
	}//end apply()

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
				'Dossiq: priority derivation could not read the payload: ' . $e->getMessage()
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
