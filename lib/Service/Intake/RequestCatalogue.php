<?php

/**
 * The request catalogue a conversational intake classifies from.
 *
 * The hermiq intake proposes which kind of request a conversation is, and the
 * proposal MUST come from the list the owning app declares: a type outside it
 * is invented, and hermiq hands such a conversation to a person. This is
 * dossiq's list.
 *
 * 🔑 IT IS CONFIGURATION, NOT A LIST IN CODE. A case type is in the catalogue
 * when it is published and places at least one AI feature on the `intake`
 * surface (`caseType.aiFeatures`, REQ-AIC-01). A municipality adds or removes
 * a request type by declaring it on the case type; nothing here names one.
 *
 * Every entry's `type` is the identifier the intake tool (`dossiq.fileCase`)
 * accepts, so a classification drawn from here is one dossiq can file.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-the-conversational-intake-files-through-dossiqs-own-create-only-path-req-aic-05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Service\Ai\CaseTypeAiFeatures;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Support\TranslatedText;

/**
 * Lists the published case types a resident's conversation may be filed under.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-the-conversational-intake-files-through-dossiqs-own-create-only-path-req-aic-05
 */
class RequestCatalogue {
	/**
	 * Constructor.
	 *
	 * @param CaseTypeStore      $caseTypes  Reads the case types.
	 * @param CaseTypeAiFeatures $aiFeatures Reads each case type's surfaces.
	 * @param TranslatedText     $text       Reads a translatable title or description as text, in the reader's language.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseTypeStore $caseTypes,
		private readonly CaseTypeAiFeatures $aiFeatures,
		private readonly TranslatedText $text = new TranslatedText(),
	) {
	}//end __construct()

	/**
	 * The catalogue: one entry per request type, in title order.
	 *
	 * @return list<array{type: string, title: string, description: string}> The request types.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-request-catalogue-is-the-case-types-declared-for-intake
	 */
	public function entries(): array {
		$entries = [];
		foreach ($this->caseTypes->everyCaseType() as $caseType) {
			if (($caseType['isDraft'] ?? false) === true) {
				continue;
			}

			if ($this->aiFeatures->onSurface(caseType: $caseType, surface: CaseTypeAiFeatures::SURFACE_INTAKE) === []) {
				continue;
			}

			$type = trim((string)($caseType['identifier'] ?? ''));
			if ($type === '') {
				$type = $this->caseTypes->rowId(row: $caseType);
			}

			if ($type === '' || isset($entries[$type]) === true) {
				// One entry per request type, however many published versions
				// share its identifier: the filing picks the version.
				continue;
			}

			$title = $this->text->forReader(value: ($caseType['title'] ?? ($caseType['name'] ?? '')));
			if ($title === '') {
				$title = $type;
			}

			$entries[$type] = [
				'type' => $type,
				'title' => $title,
				'description' => $this->text->forReader(value: ($caseType['description'] ?? '')),
			];
		}//end foreach

		$entries = array_values($entries);
		usort($entries, static fn (array $left, array $right): int => strcmp($left['title'], $right['title']));

		return $entries;
	}//end entries()
}//end class
