<?php

/**
 * An IL10N that answers in the source language.
 *
 * Guards and gates translate their refusals; a unit test reads the English
 * source with its parameters filled in, as Nextcloud does with no catalogue.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCP\IL10N;

/**
 * Returns the source text with its parameters, untranslated.
 */
class SourceL10n implements IL10N {
	/**
	 * The source text with its parameters filled in.
	 *
	 * @param string                       $text       The source text.
	 * @param array<int, string>|string    $parameters The parameters.
	 *
	 * @return string
	 */
	public function t(string $text, $parameters = []): string {
		return vsprintf($text, (array)$parameters);
	}//end t()

	/**
	 * The singular or plural source text with its parameters filled in.
	 *
	 * @param string             $singular   The singular.
	 * @param string             $plural     The plural.
	 * @param integer            $count      The count.
	 * @param array<int, string> $parameters The parameters.
	 *
	 * @return string
	 */
	public function n(string $singular, string $plural, int $count, array $parameters = []): string {
		$text = $plural;
		if ($count === 1) {
			$text = $singular;
		}

		return vsprintf(str_replace('%n', (string)$count, $text), $parameters);
	}//end n()

	/**
	 * Localization is not exercised by these tests.
	 *
	 * @param string               $type    The type.
	 * @param mixed                $data    The data.
	 * @param array<string, mixed> $options The options.
	 *
	 * @return string|integer|false
	 */
	public function l(string $type, $data, array $options = []) {
		return false;
	}//end l()

	/**
	 * The language code.
	 *
	 * @return string
	 */
	public function getLanguageCode(): string {
		return 'en';
	}//end getLanguageCode()

	/**
	 * The locale code.
	 *
	 * @return string
	 */
	public function getLocaleCode(): string {
		return 'en';
	}//end getLocaleCode()
}//end class
