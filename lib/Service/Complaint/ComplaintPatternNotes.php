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
		$noneHandled = $this->l10n->t('No complaint was handled this quarter');

		return $this->perMonthKpi(figures: $figures, targets: $targets)
			+ $this->withinAwbKpi(handling: $handling, awbTarget: $targets[ComplaintPatterns::TARGET_WITHIN_AWB], noneHandled: $noneHandled)
			+ $this->upheldKpi(handling: $handling, upheldTarget: $targets[ComplaintPatterns::TARGET_UPHELD], noneHandled: $noneHandled)
			+ $this->averageDaysKpi(handling: $handling, noneHandled: $noneHandled);
	}//end kpis()

	/**
	 * The complaints per month with its note.
	 *
	 * @param array<string, mixed> $figures The quarter's figures.
	 * @param array<string, int>   $targets The organisation's targets.
	 *
	 * @return array<string, int|string>
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	private function perMonthKpi(array $figures, array $targets): array {
		$perMonth = (int)round(((int)$figures['count']) / 3);
		$perMonthTarget = $targets[ComplaintPatterns::TARGET_PER_MONTH];

		return [
			'perMonth' => $perMonth,
			'perMonthNote' => $this->l10n->t('Target: at most %s.', [(string)$perMonthTarget]).' '
				.$this->verdict(onTarget: $perMonth <= $perMonthTarget, over: true),
		];
	}//end perMonthKpi()

	/**
	 * The share handled within the Awb term with its note.
	 *
	 * @param array<string, mixed> $handling    The quarter's handling figures.
	 * @param int                  $awbTarget   The target percentage.
	 * @param string               $noneHandled The note when nothing was handled.
	 *
	 * @return array<string, int|string|null>
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	private function withinAwbKpi(array $handling, int $awbTarget, string $noneHandled): array {
		$withinAwb = $handling['withinAwbPercent'];

		return [
			'withinAwbPercent' => $withinAwb,
			'withinAwbNote' => $this->noted(
				value: $withinAwb,
				none: $noneHandled,
				target: new TargetNote(
					note: $this->l10n->t('Target: more than %s %%.', [(string)$awbTarget]),
					onTarget: ($withinAwb ?? 0) > $awbTarget,
					over: false
				)
			),
		];
	}//end withinAwbKpi()

	/**
	 * The share upheld with its note.
	 *
	 * @param array<string, mixed> $handling     The quarter's handling figures.
	 * @param int                  $upheldTarget The target percentage.
	 * @param string               $noneHandled  The note when nothing was handled.
	 *
	 * @return array<string, int|string|null>
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	private function upheldKpi(array $handling, int $upheldTarget, string $noneHandled): array {
		$upheld = $handling['upheldPercent'];

		return [
			'upheldPercent' => $upheld,
			'upheldNote' => $this->noted(
				value: $upheld,
				none: $noneHandled,
				target: new TargetNote(
					note: $this->l10n->t('Target: less than %s %%.', [(string)$upheldTarget]),
					onTarget: ($upheld ?? 0) < $upheldTarget,
					over: true
				)
			),
		];
	}//end upheldKpi()

	/**
	 * The average handling days with its note.
	 *
	 * @param array<string, mixed> $handling    The quarter's handling figures.
	 * @param string               $noneHandled The note when nothing was handled.
	 *
	 * @return array<string, int|string|null>
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	private function averageDaysKpi(array $handling, string $noneHandled): array {
		$averageDays = $handling['averageDays'];

		return [
			'averageDays' => $averageDays,
			'averageDaysNote' => $this->noted(
				value: $averageDays,
				none: $noneHandled,
				target: new TargetNote(
					note: $this->l10n->t('Awb term 6 weeks (%s d).', [(string)ComplaintPatternFigures::AWB_TERM_DAYS]),
					onTarget: ($averageDays ?? 0) <= ComplaintPatternFigures::AWB_TERM_DAYS,
					over: true
				)
			),
		];
	}//end averageDaysKpi()

	/**
	 * A note for a figure that may be absent.
	 *
	 * @param int|null   $value  The figure.
	 * @param string     $none   The note when there is no figure.
	 * @param TargetNote $target The target sentence, and where the figure stands against it.
	 *
	 * @return string
	 */
	private function noted(?int $value, string $none, TargetNote $target): string {
		if ($value === null) {
			return $none;
		}

		return $target->note.' '.$this->verdict(onTarget: $target->onTarget, over: $target->over);
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
