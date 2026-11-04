<?php

/**
 * Dossiq PlainTextPdf.
 *
 * Writes lines of text as an A4 PDF in Helvetica: a heading line in bold and
 * the rest in regular weight, wrapped and paged. For a report a reader prints
 * or files, not for a designed document (those go through filinq's templates).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Export
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

namespace OCA\Dossiq\Service\Export;

/**
 * A text-only PDF writer.
 *
 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
 */
class PlainTextPdf {
	private const LINES_PER_PAGE = 54;
	private const WRAP_AT = 95;

	/**
	 * The PDF bytes for a title and its lines.
	 *
	 * @param string       $title The heading, in bold on the first page.
	 * @param list<string> $lines The body lines; an empty string is a blank line.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/lists-and-dashboards-as-drawn/specs/complaint-management/spec.md#requirement-the-complaint-patterns-sit-on-the-process-analysis-page
	 */
	public function render(string $title, array $lines): string {
		$wrapped = [];
		foreach ($lines as $line) {
			foreach (explode("\n", wordwrap($line, self::WRAP_AT, "\n", true)) as $part) {
				$wrapped[] = $part;
			}
		}

		$pages = array_chunk($wrapped, self::LINES_PER_PAGE);
		if ($pages === []) {
			$pages = [[]];
		}

		$objects = [
			'<< /Type /Catalog /Pages 2 0 R >>',
			'',
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
			'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
		];
		$kids = [];
		foreach ($pages as $index => $pageLines) {
			$heading = '';
			if ($index === 0) {
				$heading = $title;
			}

			$stream = $this->pageStream(title: $heading, lines: $pageLines);
			$objects[] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
			$resources = '/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >>';
			$objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '.$resources.' /Contents '.count($objects).' 0 R >>';
			$kids[] = count($objects).' 0 R';
		}

		$objects[1] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';

		return $this->assemble(objects: $objects);
	}//end render()

	/**
	 * The content stream of one page.
	 *
	 * @param string       $title The heading, or '' on a later page.
	 * @param list<string> $lines The page's lines.
	 *
	 * @return string
	 */
	private function pageStream(string $title, array $lines): string {
		$out = "BT\n/F2 14 Tf\n56 790 Td\n14 TL\n";
		if ($title !== '') {
			$out .= '('.$this->escape(text: $title).") Tj\nT*\nT*\n";
		}

		$out .= "/F1 10 Tf\n";
		foreach ($lines as $line) {
			$out .= '('.$this->escape(text: $line).") Tj\nT*\n";
		}

		return $out.'ET';
	}//end pageStream()

	/**
	 * Text as a PDF string literal body, in the font's Windows-1252 encoding.
	 *
	 * @param string $text UTF-8 text.
	 *
	 * @return string
	 */
	private function escape(string $text): string {
		$encoded = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');

		return str_replace(['\\', '(', ')', "\r"], ['\\\\', '\\(', '\\)', ''], $encoded);
	}//end escape()

	/**
	 * The objects joined into a file with its cross-reference table.
	 *
	 * @param list<string> $objects The objects, numbered from 1.
	 *
	 * @return string
	 */
	private function assemble(array $objects): string {
		$pdf = "%PDF-1.4\n";
		$offsets = [];
		foreach ($objects as $index => $body) {
			$offsets[] = strlen($pdf);
			$pdf .= ($index + 1)." 0 obj\n".$body."\nendobj\n";
		}

		$xref = strlen($pdf);
		$pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
		foreach ($offsets as $offset) {
			$pdf .= sprintf('%010d 00000 n ', $offset)."\n";
		}

		return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
	}//end assemble()
}//end class
