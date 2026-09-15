<?php

/**
 * The places somebody has written this person's name.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Queue\Source
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
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Queue\Source;

use OCA\Dossiq\Service\Queue\MentionQueueRecords;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Service\Queue\QueueSource;
use OCP\IL10N;

/**
 * Open mentions of the reader.
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */
class MentionSource implements QueueSource {
	/**
	 * Constructor.
	 *
	 * @param MentionQueueRecords $records The per-person record of a mention.
	 * @param IL10N               $l10n    Translations.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function __construct(
		private readonly MentionQueueRecords $records,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The source's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function name(): string {
		return 'mentions';
	}//end name()

	/**
	 * What the reader sees above this group.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function label(): string {
		return $this->l10n->t('You were mentioned');
	}//end label()

	/**
	 * What takes one of these off the queue.
	 *
	 * @return string The closing condition.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function closesWhen(): string {
		return $this->l10n->t('A mention leaves once you have opened what you were mentioned in.');
	}//end closesWhen()

	/**
	 * The mechanisms this source covers.
	 *
	 * @return array<int, string> The mechanism ids.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/add-work-queue/spec.md
	 */
	public function mechanisms(): array {
		return ['flow:DossiqTxNotifyNode'];
	}//end mechanisms()

	/**
	 * The mentions still waiting on this person.
	 *
	 * A mention has no date of its own and never gets one. The thing it points
	 * at may be overdue, and the queue will say so through that case's own
	 * item; giving the mention a borrowed deadline would list the same date
	 * twice and rank a remark above the work it is about.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, QueueItem> The items.
	 *
	 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
	 */
	public function itemsFor(string $userId): array {
		$items = [];
		foreach ($this->records->openFor(userId: $userId) as $row) {
			$subjectId = trim((string)($row['subjectId'] ?? ''));
			if ($subjectId === '') {
				continue;
			}

			$actor = trim((string)($row['actor'] ?? ''));
			$title = $this->l10n->t('You were mentioned in a note');
			if ($actor !== '') {
				$title = $this->l10n->t('%s mentioned you in a note', [$actor]);
			}

			$items[] = new QueueItem(
				source: $this->name(),
				subjectType: 'mention',
				subjectId: $subjectId,
				title: $title,
				priority: '',
				dueAt: null,
				coveredFor: null,
				route: ['name' => 'CaseDetail', 'params' => ['id' => $subjectId], 'query' => ['tab' => 'notes']]
			);
		}

		return $items;
	}//end itemsFor()
}//end class
