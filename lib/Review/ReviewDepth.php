<?php

/**
 * Dossiq review depth: which pages of a document a reviewer must see.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-review-depth-is-set-per-document-type-and-recorded-req-wrt-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Resolves the review depth of a document type and draws the pages it requires.
 *
 * Every page is the default. A sample is drawn from a seed that is recorded
 * on the review, so the same pages come out whenever the draw is repeated:
 * when the page count arrives after the batch was made, and when someone
 * checks the sample afterwards.
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-review-depth-is-set-per-document-type-and-recorded-req-wrt-004
 */
class ReviewDepth {

	public const EVERY_PAGE = 'every-page';

	public const SAMPLE = 'sample';

	/**
	 * Mime fragments that make a document a spreadsheet, the class exports usually fall in.
	 */
	private const SPREADSHEET_HINTS = ['spreadsheet', 'excel', 'csv', 'opendocument.spreadsheet'];

	/**
	 * The depth that applies to a document type, with a fresh seed for a sample.
	 *
	 * @param array<string, mixed> $reviewDepth The configuration's `reviewDepth`, by type.
	 * @param string $type The document type, as typeOf() answers it.
	 *
	 * @return array{mode: string, sampleSize?: int, seed?: int} The depth to record on the review.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-review-depth-is-set-per-document-type-and-recorded-req-wrt-004
	 */
	public function depthFor(array $reviewDepth, string $type): array {
		$entry = ($reviewDepth[$type] ?? null);
		if (is_array($entry) === false || ($entry['mode'] ?? '') !== self::SAMPLE) {
			return ['mode' => self::EVERY_PAGE];
		}

		$size = (int)($entry['sampleSize'] ?? 0);
		if ($size < 1) {
			return ['mode' => self::EVERY_PAGE];
		}

		return ['mode' => self::SAMPLE, 'sampleSize' => $size, 'seed' => random_int(1, PHP_INT_MAX)];
	}//end depthFor()

	/**
	 * The page numbers a reviewer must see, ascending.
	 *
	 * A document whose length is not known yet requires its first page, so
	 * a verdict always needs the document opened; the full set follows once
	 * the viewer reports the page count.
	 *
	 * @param array<string, mixed> $depth The recorded depth.
	 * @param int|null $pageCount The document's page count, null when unknown.
	 *
	 * @return list<int> The required pages.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-review-depth-is-set-per-document-type-and-recorded-req-wrt-004
	 */
	public function pagesRequired(array $depth, ?int $pageCount): array {
		if ($pageCount === null || $pageCount < 1) {
			return [1];
		}

		$all = range(1, $pageCount);
		$size = (int)($depth['sampleSize'] ?? 0);
		if (($depth['mode'] ?? self::EVERY_PAGE) !== self::SAMPLE || $size >= $pageCount) {
			return $all;
		}

		$randomizer = new Randomizer(new Mt19937((int)($depth['seed'] ?? 0)));
		$pages = array_map(static fn (int $key): int => $all[$key], $randomizer->pickArrayKeys($all, max(1, $size)));
		sort($pages);

		return $pages;
	}//end pagesRequired()

	/**
	 * The type a depth is configured by: the informatieobjecttype, or the class of the mime type.
	 *
	 * @param array<string, mixed> $document The document.
	 *
	 * @return string The type, or '' when the document says nothing about it.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-review-depth-is-set-per-document-type-and-recorded-req-wrt-004
	 */
	public function typeOf(array $document): string {
		$type = trim((string)($document['informatieobjecttype'] ?? ''));
		if ($type !== '') {
			return $type;
		}

		$mime = strtolower(trim((string)($document['mimeType'] ?? ($document['formaat'] ?? ''))));
		if ($mime === '') {
			return '';
		}

		foreach (self::SPREADSHEET_HINTS as $hint) {
			if (str_contains($mime, $hint) === true) {
				return 'spreadsheet';
			}
		}

		return match (true) {
			$mime === 'application/pdf' => 'pdf',
			str_starts_with($mime, 'message/') => 'mail',
			default => explode('/', $mime)[0],
		};
	}//end typeOf()
}//end class
