<?php

/**
 * Dossiq ComplaintPatternsPdf.
 *
 * The system report of a quarter's complaint patterns as a PDF, for the
 * "Export as pdf" action on the process analysis page.
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

use OCA\Dossiq\Service\Export\PlainTextPdf;
use OCP\IL10N;

/**
 * Writes the patterns answer as a PDF report.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 */
class ComplaintPatternsPdf {
	/**
	 * Constructor.
	 *
	 * @param IL10N        $l10n   Translates the headings.
	 * @param PlainTextPdf $writer Writes the PDF.
	 */
	public function __construct(
		private readonly IL10N $l10n,
		private readonly PlainTextPdf $writer,
	) {
	}//end __construct()

	/**
	 * The PDF bytes for one answer of {@see ComplaintPatterns::forQuarter()}.
	 *
	 * @param array<string, mixed> $patterns The answer.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	public function render(array $patterns): string {
		$kpis = $patterns['kpis'];
		$lines = [
			(string)$patterns['quarterLabel'],
			'',
			$this->l10n->t('Complaints per month: %1$s. %2$s', [(string)$kpis['perMonth'], $kpis['perMonthNote']]),
			$this->l10n->t('Handled within the Awb term: %1$s %%. %2$s', [(string)$kpis['withinAwbPercent'], $kpis['withinAwbNote']]),
			$this->l10n->t('Well-founded: %1$s %%. %2$s', [(string)$kpis['upheldPercent'], $kpis['upheldNote']]),
			$this->l10n->t('Average handling time: %1$s days. %2$s', [(string)$kpis['averageDays'], $kpis['averageDaysNote']]),
			'',
		];

		foreach ($patterns['findings'] as $finding) {
			array_push($lines, $finding['title'], $finding['text'], '');
		}

		$lines[] = $this->l10n->t('Complaints per category');
		foreach ($patterns['byCategory']['labels'] as $index => $label) {
			$lines[] = '  '.$label.': '.$patterns['byCategory']['counts'][$index];
		}

		$lines[] = '';
		$lines[] = $this->l10n->t('Average handling time per category');
		foreach ($patterns['resolutionByCategory'] as $row) {
			$values = [$row['category'], (string)$row['count'], (string)$row['averageDays'], (string)$row['upheldPercent']];
			$lines[] = '  '.$this->l10n->t('%1$s: %2$s complaints, %3$s days on average, %4$s %% well-founded', $values);
		}

		return $this->writer->render(title: $this->l10n->t('Complaints: recurring patterns'), lines: $lines);
	}//end render()
}//end class
