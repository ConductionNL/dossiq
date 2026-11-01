<?php

/**
 * One term letter to render: the case it is about, the term's end date, the
 * language it is written in and the caller's context.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Term
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/burger-notifications/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

/**
 * The inputs every term letter is written from.
 *
 * @spec openspec/specs/burger-notifications/spec.md
 */
final class TermLetter {

	/**
	 * Constructor.
	 *
	 * @param string               $reference The case kenmerk, or '–' when unknown.
	 * @param string               $end       The term's current end date as shown, or '–'.
	 * @param string               $locale    The declared language.
	 * @param array<string, mixed> $context   The render context.
	 */
	public function __construct(
		public readonly string $reference,
		public readonly string $end,
		public readonly string $locale,
		public readonly array $context,
	) {
	}//end __construct()

	/**
	 * Whether the letter is written in English.
	 *
	 * @return bool True for English.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	public function isEnglish(): bool {
		return $this->locale === 'en';
	}//end isEnglish()

	/**
	 * A context value as trimmed text, or '' when absent.
	 *
	 * @param string $key The context key.
	 *
	 * @return string The text.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	public function text(string $key): string {
		return trim((string)($this->context[$key] ?? ''));
	}//end text()
}//end class
