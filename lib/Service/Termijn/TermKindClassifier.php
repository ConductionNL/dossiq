<?php

/**
 * Dossiq term kind classifier.
 *
 * Which of the term kinds a TermijnInstance row is. Every instance written
 * before term kinds existed is a statutory term, so an absent `kind` reads as
 * one. An unknown value reads as statutory too: treating a clock nobody
 * recognises as the citizen's term is the reading that shows it rather than
 * the reading that hides it.
 *
 * This is the one place the rule lives. It is an injected service so a caller
 * needs no static call; {@see \OCA\Dossiq\Service\TermKind::ofInstance()}
 * answers through it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use OCA\Dossiq\Service\TermKind;

/**
 * Classify a term instance by its kind.
 *
 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
 */
class TermKindClassifier {

	/**
	 * The kind of a TermijnInstance row.
	 *
	 * @param array<string, mixed> $instance The TermijnInstance row.
	 *
	 * @return string One of {@see TermKind::ALL}; statutory when absent or unknown.
	 *
	 * @spec openspec/changes/phase-terms-and-the-internal-target/specs/termijn-binding/spec.md
	 */
	public function ofInstance(array $instance): string {
		$kind = (string)($instance['kind'] ?? '');
		if (in_array($kind, TermKind::ALL, true) === true) {
			return $kind;
		}

		return TermKind::STATUTORY;
	}//end ofInstance()
}//end class
