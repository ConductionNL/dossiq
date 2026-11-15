<?php

/**
 * Dossiq QueueSourceStatus.
 *
 * Which of the sources a queue merges did not answer, and the sentence a
 * queue shows about them (board DqTeamwachtrij, "Bron niet beschikbaar").
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue
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
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue;

use OCA\Dossiq\Kernel\Contracts\QueueSourceStatusInterface;
use OCP\IL10N;

/**
 * Reports the queue sources that did not answer.
 *
 * A source that fails is named, never left out quietly: a shorter queue that
 * looks like a quiet day is the failure this answer exists to prevent.
 *
 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
 */
class QueueSourceStatus implements QueueSourceStatusInterface {
	/**
	 * The source that carries dossiq's own cases.
	 */
	private const OWN_CASES = 'assigned-cases';

	/**
	 * Constructor.
	 *
	 * @param PersonalQueueService $queue The queue, read the way the reader's queue is read.
	 * @param IL10N                $l10n  Translates the sentence.
	 */
	public function __construct(
		private readonly PersonalQueueService $queue,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The sources that did not answer for this reader, and the sentence about them.
	 *
	 * @param string $userId The reader.
	 *
	 * @return array{available: bool, unavailable: list<array{source: string, label: string}>, unavailableText: string}
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md
	 */
	public function forReader(string $userId): array {
		$unavailable = [];
		foreach ((array)($this->queue->forPerson(userId: $userId)['unavailable'] ?? []) as $source) {
			$unavailable[] = ['source' => (string)($source['source'] ?? ''), 'label' => (string)($source['label'] ?? ($source['source'] ?? ''))];
		}

		return ['available' => true, 'unavailable' => $unavailable, 'unavailableText' => $this->sentence(unavailable: $unavailable)];
	}//end forReader()

	/**
	 * The sentence: which sources are missing, and whether the cases themselves are complete.
	 *
	 * @param list<array{source: string, label: string}> $unavailable The sources that did not answer.
	 *
	 * @return string '' when every source answered.
	 */
	private function sentence(array $unavailable): string {
		if ($unavailable === []) {
			return '';
		}

		$text = $this->l10n->t('Source not available: %s. Those are not in this queue now.', [implode(', ', array_column($unavailable, 'label'))]);
		if (in_array(self::OWN_CASES, array_column($unavailable, 'source'), true) === false) {
			$text .= ' '.$this->l10n->t('The cases of dossiq itself are complete.');
		}

		return $text;
	}//end sentence()
}//end class
