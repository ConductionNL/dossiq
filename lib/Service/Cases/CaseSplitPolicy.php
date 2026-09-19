<?php

/**
 * What a split may divide out of a case, and what it may not.
 *
 * 🔑 THE HANDLER CHOOSES, THE CASE TYPE BOUNDS THE CHOICE (D-2). Which
 * documents belong to which half is a judgement about the content, and only
 * the handler has it. What may be divided AT ALL is a rule that does not
 * change per case, so it is declared once on the case type and the handler is
 * offered the choices that are allowed and no others.
 *
 * 🔴 ABSENT MEANS ALL THREE, NOT NONE. Every case type on every install
 * predates this declaration, and a rule that read silence as "nothing may be
 * divided" would ship a split action that refuses every split on the day it
 * arrives. A case type that genuinely forbids one part says so.
 *
 * 🔴 THE REFUSAL NAMES THE RULE (ADR-050). "Not allowed" sends a handler to
 * the rights matrix. "This case type does not allow documents to be divided"
 * sends them to the case type, which is where the answer is, and tells them
 * the split of the rest is still available.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

/**
 * Reads a case type's split declaration and judges one selection against it.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitPolicy {
	/**
	 * The case-type field declaring what a split may divide.
	 *
	 * @var string
	 */
	public const DECLARATION = 'splittableParts';

	/**
	 * The parts a split can divide.
	 *
	 * @var array<int, string>
	 */
	public const PARTS = ['documents', 'parties', 'tasks'];

	/**
	 * What one case type allows.
	 *
	 * @param array<string, mixed>|null $caseType The case type, as stored.
	 *
	 * @return array<int, string> The parts this type allows, in a stable order.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-type-declares-what-a-split-may-divide-req-cm-52
	 */
	public function allowedFor(?array $caseType): array {
		$declared = ($caseType[self::DECLARATION] ?? null);
		if (is_array($declared) === false) {
			// Not declared: everything may be divided, which is what every
			// case type on every install meant before this key existed.
			return self::PARTS;
		}

		$allowed = [];
		foreach (self::PARTS as $part) {
			if (in_array($part, array_map('strval', $declared), true) === true) {
				$allowed[] = $part;
			}
		}

		return $allowed;
	}//end allowedFor()

	/**
	 * Why a selection is refused, or an empty string when it may proceed.
	 *
	 * @param array<int, string> $selected The parts the handler chose to divide.
	 * @param array<string, mixed>|null $caseType The case type.
	 *
	 * @return string The refusal, '' when the split may go ahead.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-type-declares-what-a-split-may-divide-req-cm-52
	 */
	public function whyRefused(array $selected, ?array $caseType): string {
		$allowed = $this->allowedFor(caseType: $caseType);

		$refused = [];
		foreach ($selected as $part) {
			$part = (string)$part;
			if (in_array($part, self::PARTS, true) === false) {
				return sprintf('A split divides documents, parties or tasks, and "%s" is none of those.', $part);
			}

			if (in_array($part, $allowed, true) === false) {
				$refused[] = $part;
			}
		}

		if ($refused === []) {
			return '';
		}

		// The sentence names what may still be divided, because a handler told
		// only what they may not do has to guess at the rest.
		$remaining = 'This case type allows no part of a case to be divided.';
		if ($allowed !== []) {
			$remaining = sprintf('It allows %s to be divided.', implode(' and ', $allowed));
		}

		return sprintf(
			'This case type does not allow %s to be divided. %s',
			implode(' and ', $refused),
			$remaining
		);
	}//end whyRefused()
}//end class
