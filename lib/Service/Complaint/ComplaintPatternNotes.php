<?php

/**
 * Dossiq ComplaintPatternNotes.
 *
 * The four complaint figures of a quarter, each with the line under it that
 * holds it against the organisation's target ("Doel: maximaal 10. Boven het
 * doel").
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Complaint
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
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Complaint;

use OCP\IL10N;

/**
 * Words the four figures against their targets.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 */
class ComplaintPatternNotes {
	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translates the notes.
	 */
	public function __construct(
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The four figures with their notes.
	 *
	 * @param array<string, mixed> $figures The quarter's figures from {@see ComplaintPatternFigures::compute()}.
	 * @param array<string, int>   $targets The organisation's targets.
	 *
	 * @return array<string, int|string|null>
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	public function kpis(array $figures, array $targets): array {
		$handling = $figures['handling'];
		$perMonth = (int)round(((int)$figures['count']) / 3);
		$withinAwb = $handling['withinAwbPercent'];
		$upheld = $handling['upheldPercent'];
		$averageDays = $handling['averageDays'];
		$noneHandled = $this->l10n->t('No complaint was handled this quarter');

		$perMonthTarget = $targets[ComplaintPatterns::TARGET_PER_MONTH];
		$awbTarget = $targets[ComplaintPatterns::TARGET_WITHIN_AWB];
		$upheldTarget = $targets[ComplaintPatterns::TARGET_UPHELD];

		return [
			'perMonth' => $perMonth,
			'perMonthNote' => $this->l10n->t('Target: at most %s.', [(string)$perMonthTarget]).' '
				.$this->verdict(onTarget: $perMonth <= $perMonthTarget, over: true),
			'withinAwbPercent' => $withinAwb,
			'withinAwbNote' => $this->noted(
				value: $withinAwb,
				none: $noneHandled,
				note: $this->l10n->t('Target: more than %s %%.', [(string)$awbTarget]),
				onTarget: ($withinAwb ?? 0) > $awbTarget,
				over: false
			),
			'upheldPercent' => $upheld,
			'upheldNote' => $this->noted(
				value: $upheld,
				none: $noneHandled,
				note: $this->l10n->t('Target: less than %s %%.', [(string)$upheldTarget]),
				onTarget: ($upheld ?? 0) < $upheldTarget,
				over: true
			),
			'averageDays' => $averageDays,
			'averageDaysNote' => $this->noted(
				value: $averageDays,
				none: $noneHandled,
				note: $this->l10n->t('Awb term 6 weeks (%s d).', [(string)ComplaintPatternFigures::AWB_TERM_DAYS]),
				onTarget: ($averageDays ?? 0) <= ComplaintPatternFigures::AWB_TERM_DAYS,
				over: true
			),
		];
	}//end kpis()

	/**
	 * A note for a figure that may be absent.
	 *
	 * @param int|null $value    The figure.
	 * @param string   $none     The note when there is no figure.
	 * @param string   $note     The target sentence.
	 * @param bool     $onTarget Whether the figure meets its target.
	 * @param bool     $over     Whether missing the target means being above it.
	 *
	 * @return string
	 */
	private function noted(?int $value, string $none, string $note, bool $onTarget, bool $over): string {
		if ($value === null) {
			return $none;
		}

		return $note.' '.$this->verdict(onTarget: $onTarget, over: $over);
	}//end noted()

	/**
	 * "On target", or which side of it the figure is.
	 *
	 * @param bool $onTarget Whether the figure meets its target.
	 * @param bool $over     Whether missing the target means being above it.
	 *
	 * @return string
	 */
	private function verdict(bool $onTarget, bool $over): string {
		if ($onTarget === true) {
			return $this->l10n->t('On target');
		}

		if ($over === true) {
			return $this->l10n->t('Above target');
		}

		return $this->l10n->t('Below target');
	}//end verdict()
}//end class
