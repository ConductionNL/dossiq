<?php

/**
 * The summary rows of the Mijn werk dashboard widget.
 *
 * Counts the signed-in person's queue the way board DqNextcloudDashboard
 * draws it: open cases on their name (with how many they hold as substitute)
 * and open tasks (with how many are late or due today).
 *
 * @category Dashboard
 * @package  OCA\Dossiq\Dashboard
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
 * @spec openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Dashboard;

use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCP\Dashboard\Model\WidgetItem;
use OCP\IL10N;

/**
 * Turns queue items into the widget's summary rows.
 *
 * @spec openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md
 */
class OpenWorkSummary {
	/**
	 * Constructor.
	 *
	 * @param IL10N              $l10n  Translations.
	 * @param QueueWidgetItems   $items The My work link and the app icon.
	 * @param CaseDateNormaliser $dates Today, on the organisation's calendar.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly QueueWidgetItems $items,
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * The summary rows: cases, then tasks; a kind with nothing open is left out.
	 *
	 * @param array<int, QueueItem> $queueItems The person's queue.
	 *
	 * @return array<int, WidgetItem> The rows.
	 *
	 * @spec openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md
	 */
	public function rows(array $queueItems): array {
		$rows = [
			$this->caseRow(cases: $this->ofType(queueItems: $queueItems, type: 'case')),
			$this->taskRow(tasks: $this->ofType(queueItems: $queueItems, type: 'task')),
		];

		return array_values(array_filter($rows));
	}//end rows()

	/**
	 * The row for open cases on the person's name.
	 *
	 * @param array<int, QueueItem> $cases The case items.
	 *
	 * @return WidgetItem|null The row, or null without cases.
	 *
	 * @spec openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md
	 */
	private function caseRow(array $cases): ?WidgetItem {
		if ($cases === []) {
			return null;
		}

		$covered = count(array_filter($cases, static fn (QueueItem $item): bool => $item->isCovered()));
		$subtitle = '';
		if ($covered > 0) {
			$subtitle = $this->l10n->n('%n as substitute', '%n as substitute', $covered);
		}

		return $this->row(title: $this->l10n->n('%n open case on your name', '%n open cases on your name', count($cases)), subtitle: $subtitle);
	}//end caseRow()

	/**
	 * The row for open tasks, with how many are late and due today.
	 *
	 * @param array<int, QueueItem> $tasks The task items.
	 *
	 * @return WidgetItem|null The row, or null without tasks.
	 *
	 * @spec openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md
	 */
	private function taskRow(array $tasks): ?WidgetItem {
		if ($tasks === []) {
			return null;
		}

		$today = $this->dates->todayAsCalendarDate();
		$late = count(array_filter($tasks, static fn (QueueItem $item): bool => $item->dueAt !== null && substr($item->dueAt, 0, 10) < $today));
		$dueToday = count(array_filter($tasks, static fn (QueueItem $item): bool => $item->dueAt !== null && substr($item->dueAt, 0, 10) === $today));
		$subtitle = $this->l10n->t('%1$s late, %2$s today', [(string)$late, (string)$dueToday]);

		return $this->row(title: $this->l10n->n('%n open task', '%n open tasks', count($tasks)), subtitle: $subtitle);
	}//end taskRow()

	/**
	 * The queue items of one subject type.
	 *
	 * @param array<int, QueueItem> $queueItems The person's queue.
	 * @param string                $type       The subject type.
	 *
	 * @return array<int, QueueItem> The matching items.
	 *
	 * @spec openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md
	 */
	private function ofType(array $queueItems, string $type): array {
		return array_values(array_filter($queueItems, static fn (QueueItem $item): bool => $item->subjectType === $type));
	}//end ofType()

	/**
	 * One summary row, linking to My work.
	 *
	 * @param string $title    What the row counts.
	 * @param string $subtitle The detail under it.
	 *
	 * @return WidgetItem The row.
	 *
	 * @spec openspec/changes/one-mijn-werk-widget/specs/dashboard/spec.md
	 */
	private function row(string $title, string $subtitle): WidgetItem {
		return new WidgetItem($title, $subtitle, $this->items->myWorkUrl(), $this->items->iconUrl(), $title);
	}//end row()
}//end class
