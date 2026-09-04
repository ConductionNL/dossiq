<?php

/**
 * Dossiq VTH catalogue report.
 *
 * Collects what happened to every entry in the VTH workflow-template catalogue
 * and writes it out as the summary an administrator reads.
 *
 * 🔴 A COUNT IS NOT A REPORT, AND THAT IS WHY THIS CLASS EXISTS. The seed step
 * used to print "0 seeded, 5 skipped (already present or unresolved)" and name
 * one of the five. An entry that never landed read exactly like one that landed
 * last time, so `toezichtbezoek` was dropped on every install for the life of
 * the catalogue and no line ever said so.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Vth
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
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Vth;

use OCP\Migration\IOutput;

/**
 * What happened to each VTH catalogue entry, and how it is reported.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/vth-workflow-templates/spec.md
 */
class VthCatalogueReport {

	/**
	 * The outcomes recorded so far, in catalogue order.
	 *
	 * @var array<int, array{entry: string, outcome: string, reason: string}>
	 */
	private array $outcomes = [];

	/**
	 * Forget every recorded outcome.
	 *
	 * The container hands out one instance, so a step that runs twice in one
	 * process would otherwise report the first run's entries again.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function reset(): void {
		$this->outcomes = [];
	}//end reset()

	/**
	 * Record one entry's result.
	 *
	 * @param string $entry The catalogue entry, by slug or file name.
	 * @param string $outcome One of seeded|published|present|skipped|crossLink|failed.
	 * @param string $reason What happened to it, in one sentence.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function record(string $entry, string $outcome, string $reason): void {
		$this->outcomes[] = ['entry' => $entry, 'outcome' => $outcome, 'reason' => $reason];
	}//end record()

	/**
	 * Write the summary, one line per catalogue entry.
	 *
	 * A run that left something undone closes with the command that finishes it.
	 *
	 * @param IOutput $output The output interface.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function write(IOutput $output): void {
		$counts = ['seeded' => 0, 'published' => 0, 'present' => 0, 'skipped' => 0, 'crossLink' => 0, 'failed' => 0];
		foreach ($this->outcomes as $outcome) {
			$counts[$outcome['outcome']] = ($counts[$outcome['outcome']] ?? 0) + 1;
		}

		$output->info(
			'VTH workflow templates: ' . $counts['seeded'] . ' seeded, '
			. $counts['published'] . ' published from an earlier draft, '
			. $counts['present'] . ' already present, '
			. $counts['skipped'] . ' skipped, '
			. $counts['crossLink'] . ' cross-link, '
			. $counts['failed'] . ' failed. ' . count($this->outcomes) . ' catalogue entries in total.'
		);

		foreach ($this->outcomes as $outcome) {
			$output->info('  ' . $outcome['entry'] . ': ' . $outcome['reason']);
		}

		if (($counts['skipped'] + $counts['failed']) > 0) {
			$output->info(
				'Fix what the skipped and failed lines above name, then run `occ maintenance:repair` again.'
			);
		}
	}//end write()

	/**
	 * The title of the definition a publish displaced, or an empty string.
	 *
	 * 🔴 PUBLISHING THE SECOND TEMPLATE FOR A CASE TYPE DEPRECATES THE FIRST,
	 * AND NOTHING USED TO SAY SO. One published definition per case type is the
	 * workflow-definition model working as designed, but the catalogue ships
	 * two entries against `handhavingszaak`: `handhavingstraject` and
	 * `spoedig-herstel`, the Awb 5:31 spoedeisende route. Whichever the glob
	 * reaches last retires the other, and an administrator's only clue used to
	 * be a workflow that had quietly stopped backing new cases.
	 *
	 * The caller reads the active definition BEFORE publishing and hands it
	 * here, so the summary can name what went.
	 *
	 * @param array<string, mixed>|null $displaced The definition that was active before the publish.
	 * @param string $publishedId The uuid that was just published.
	 *
	 * @return string The displaced title, or '' when this publish displaced nothing.
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function displacedTitle(?array $displaced, string $publishedId): string {
		$title = (string)(($displaced['title'] ?? ''));
		if ($title === '' || (string)(($displaced['id'] ?? '')) === $publishedId) {
			return '';
		}

		return $title;
	}//end displacedTitle()

	/**
	 * The summary line for a template this run seeded and published.
	 *
	 * @param string $title The template's title.
	 * @param int $version The version that was published.
	 * @param string $displacedTitle The definition this publish deprecated, or ''.
	 *
	 * @return string The sentence the administrator reads.
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function seededReason(string $title, int $version, string $displacedTitle): string {
		$reason = 'seeded and published as "' . $title . '" version ' . $version . '.';
		if ($displacedTitle === '') {
			return $reason;
		}

		return $reason . ' This deprecated "' . $displacedTitle
			. '", the definition that case type had: one published definition per case type is'
			. ' the model, and this case type has two catalogue entries.';
	}//end seededReason()

	/**
	 * Render a list of names as a readable, quoted enumeration.
	 *
	 * @param array<int, string> $values The names.
	 *
	 * @return string The quoted list, or "none" when there are no names.
	 *
	 * @spec openspec/specs/vth-workflow-templates/spec.md
	 */
	public function quotedList(array $values): string {
		if ($values === []) {
			return 'none';
		}

		return '"' . implode('", "', $values) . '"';
	}//end quotedList()
}//end class
