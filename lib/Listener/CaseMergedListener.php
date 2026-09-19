<?php

/**
 * Dossiq Case Merged Listener.
 *
 * OpenRegister raises one event for both directions of a merge: the merge
 * itself and its reversal inside the window. This listener is dossiq's
 * follower on that event (ADR-078), and it does nothing that OpenRegister
 * already did. It hands the two case ids to {@see CaseMergeService}, which
 * moves the dossiq rows, writes `mergedInto` and closes or re-arms the term.
 *
 * The event class belongs to OpenRegister and is optional at runtime, so the
 * handler duck-types every reader it uses. An event that does not answer to
 * them is not this listener's event, and is left alone rather than guessed at.
 *
 * Failures are swallowed and logged: a merge that OpenRegister has already
 * committed must not be undone by a follower that could not finish, and an
 * exception here would only fail the dispatch, never the merge.
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
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\CaseMergeService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Applies dossiq's half of an OpenRegister merge on a `case`.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
 */
class CaseMergedListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param CaseMergeService $mergeService The dossiq consequences of a merge.
	 * @param LoggerInterface  $logger       Logger.
	 */
	public function __construct(
		private readonly CaseMergeService $mergeService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Handle OpenRegister's `ObjectsMergedEvent`.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function handle(Event $event): void {
		if (method_exists($event, 'getSurvivorUuid') === false
			|| method_exists($event, 'getMergedFromUuids') === false
			|| method_exists($event, 'isReversal') === false
		) {
			return;
		}

		$survivorId = (string)$event->getSurvivorUuid();
		$mergedIds = (array)$event->getMergedFromUuids();
		if ($survivorId === '' || $mergedIds === []) {
			return;
		}

		$isReversal = ($event->isReversal() === true);

		foreach ($mergedIds as $mergedId) {
			$this->apply(mergedId: (string)$mergedId, survivorId: $survivorId, isReversal: $isReversal);
		}
	}//end handle()

	/**
	 * Apply one direction of the merge to one case.
	 *
	 * @param string $mergedId   The case that was merged away.
	 * @param string $survivorId The surviving case.
	 * @param bool   $isReversal True when the platform reversed the merge.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The event says which
	 *   direction it is; this method does not decide it.
	 */
	private function apply(string $mergedId, string $survivorId, bool $isReversal): void {
		try {
			if ($isReversal === true) {
				$this->mergeService->applyReversal(mergedId: $mergedId, survivorId: $survivorId);
				return;
			}

			$this->mergeService->applyMerge(mergedId: $mergedId, survivorId: $survivorId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the merge of case "' . $mergedId . '" into "' . $survivorId
				. '" could not be followed: ' . $e->getMessage()
			);
		}
	}//end apply()
}//end class
