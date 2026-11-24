<?php

/**
 * A transition's label as text.
 *
 * A transition's `label` is either a plain string or a language map
 * (`{"nl": "In behandeling nemen", "en": "Take on"}`), the same shape as the
 * other translatable names. This reads it in the reader's language, as source
 * text, or as every text it holds.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Support\TranslatedText;

/**
 * Reads a transition's label, a string or a language map.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
class TransitionLabel {
	/**
	 * Constructor.
	 *
	 * @param TranslatedText  $reader A translatable text in the reader's language.
	 * @param LanguageMapText $text   A translatable text in its source language.
	 */
	public function __construct(
		private readonly TranslatedText $reader = new TranslatedText(),
		private readonly LanguageMapText $text = new LanguageMapText(),
	) {
	}//end __construct()

	/**
	 * The label in the reader's language.
	 *
	 * @param array<string, mixed> $transition The transition.
	 *
	 * @return string The label, '' when it has none.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function forReader(array $transition): string {
		return $this->reader->forReader(value: ($transition['label'] ?? ''));
	}//end forReader()

	/**
	 * The label in its source language, for logs and other text no reader picks.
	 *
	 * @param array<string, mixed> $transition The transition.
	 *
	 * @return string The label, '' when it has none.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function source(array $transition): string {
		return $this->text->textOf(value: ($transition['label'] ?? ''));
	}//end source()

	/**
	 * Every text the label holds, one per language.
	 *
	 * @param array<string, mixed> $transition The transition.
	 *
	 * @return list<string> The texts, trimmed, without empty ones.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function texts(array $transition): array {
		$label = ($transition['label'] ?? '');
		if (is_array($label) === false) {
			$label = [$label];
		}

		$texts = [];
		foreach ($label as $value) {
			if (is_string($value) === true && trim($value) !== '') {
				$texts[] = trim($value);
			}
		}

		return $texts;
	}//end texts()
}//end class
