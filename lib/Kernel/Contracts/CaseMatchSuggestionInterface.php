<?php

/**
 * Contract of CaseMatchSuggestion
 *
 * The public surface of CaseMatchSuggestion that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of CaseMatchSuggestion.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseMatchSuggestionInterface {

	/**
	 * Add a `suggestion` to every queue item: the case it probably belongs to, or null.
	 *
	 * @param array<int, array<string, mixed>> $items The queue items.
	 *
	 * @return array<int, array<string, mixed>> The items, each with `suggestion`.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	public function annotate(array $items): array;

	/**
	 * Keep the items that look like a case (`IS NOT NULL`) or the ones that do not (`IS NULL`); anything else keeps all.
	 *
	 * @param array<int, array<string, mixed>> $items      Annotated queue items.
	 * @param string                           $suggestion The filter as a list sends it.
	 *
	 * @return array<int, array<string, mixed>> The items kept.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	public function narrow(array $items, string $suggestion): array;
}//end interface
