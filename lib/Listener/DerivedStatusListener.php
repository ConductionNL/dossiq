<?php

/**
 * Move a case into a status that has become true.
 *
 * A status that declares its conditions is not something a handler picks. It
 * becomes true when the file becomes what it describes, and the moment that
 * happens is a WRITE to the case: the fourth document arrives, the form answer
 * lands. So the derivation runs on the save that made it true, in the same
 * write, rather than in a sweep that would notice the next morning.
 *
 * Writing the status here rather than through `StatusTransitionService` is
 * deliberate and narrow. The engine's `execute()` takes a transition id, and a
 * derived status has no transition: that is the whole point of D-1. What the
 * engine owns and this listener does not take is the guard evaluation and the
 * side effects, so a derived move brings no transition's automatic actions
 * with it.
 *
 * 🔴 IT WRITES NO `statusRecord`, AND THAT IS A CHOICE RATHER THAN AN
 * OVERSIGHT. This runs BEFORE the case is persisted, so a record written here
 * would survive a save that then failed and the history would carry a move
 * that never happened. The derived move is visible in two places that cannot
 * lie about it: OpenRegister's own audit trail of the case, and the
 * `statusDwellTotals` this listener settles in the same write, which is what
 * the process mining page now reads. Putting the record back needs a
 * post-persist listener that compares the two statuses, which is the shape to
 * reach for when the case timeline is asked to show derivations.
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
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Status\DerivedStatusService;
use OCA\Dossiq\Service\Status\StatusDeclarations;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sets a case's status when the case type derives it.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
class DerivedStatusListener implements IEventListener {

	/**
	 * The dwell bookkeeping a move settles, and the only keys of it this
	 * listener carries into the save.
	 *
	 * `applyStatusChange()` answers with a whole case payload, and merging that
	 * into `modifiedData` would write back every property the case already
	 * has, turning a one-field derivation into a full rewrite — and, with it,
	 * every read-state row the unread declaration drops on a substantive write.
	 *
	 * @var array<int, string>
	 */
	private const DWELL_FIELDS = [
		'statusDwellTotals',
		'currentStatusEnteredAt',
		'currentStatusDwellDays',
		'statusDwellBreached',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService      $settingsService Schema slug bridge.
	 * @param DerivedStatusService $derived         Which status the case type derives.
	 * @param StatusDeclarations   $declarations    The dwell bookkeeping a move carries.
	 * @param LoggerInterface      $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly DerivedStatusService $derived,
		private readonly StatusDeclarations $declarations,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Derive the status of a case about to be written.
	 *
	 * Only on UPDATE. A case being created has no documents and no form
	 * answers yet — its status comes from the case type's `initialStatus`
	 * through OpenRegister's own prefill — so deriving on create would race
	 * that prefill for a verdict that is `false` in every condition anyway.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === false) {
			return;
		}

		$payload = $this->payload(entity: $event->getNewObject());
		if ($payload === null || $this->isCaseSchema(object: $payload) === false) {
			return;
		}

		$modified = $event->getModifiedData();
		$payload = array_merge($payload, $modified);

		$target = $this->derived->statusFor(case: $payload);
		if ($target === null) {
			return;
		}

		$settled = $this->declarations->applyStatusChange(case: $payload, toStatus: $target);
		$dwell = array_intersect_key($settled, array_flip(self::DWELL_FIELDS));

		$event->setModifiedData(
			array_merge(
				$modified,
				$dwell,
				['status' => $target],
			)
		);

		$this->logger->info(
			'Dossiq: a declared status became true and the case moved into it',
			[
				'case' => (string)($payload['id'] ?? ($payload['@self']['id'] ?? '')),
				'from' => (string)($payload['status'] ?? ''),
				'to' => $target,
			]
		);
	}//end handle()

	/**
	 * Read an entity's payload, or null when it cannot be read.
	 *
	 * @param ObjectEntity $entity The entity carried by the event.
	 *
	 * @return array<string, mixed>|null The payload, or null.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	private function payload(ObjectEntity $entity): ?array {
		try {
			return $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: the status derivation could not read the payload: ' . $e->getMessage()
			);
			return null;
		}
	}//end payload()

	/**
	 * Whether the supplied payload belongs to the `case` schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return bool True when this is a case.
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
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
