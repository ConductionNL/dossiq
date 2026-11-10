<?php

/**
 * Dossiq Woo source search.
 *
 * One search a handler runs in a Woo request's external sources: the case,
 * the source, what was searched for, the period and who searched.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-case-type/spec.md
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

/**
 * A search in a Woo request's sources, as the request asks it.
 *
 * Built by {@see WooSourcesController::search()} only; it never leaves it.
 *
 * @spec openspec/specs/woo-case-type/spec.md
 */
final class WooSourceSearch {

	/**
	 * The Woo case.
	 *
	 * @var string
	 */
	public string $caseId = '';

	/**
	 * The source searched.
	 *
	 * @var string
	 */
	public string $source = '';

	/**
	 * What was searched for, trimmed.
	 *
	 * @var string
	 */
	public string $terms = '';

	/**
	 * The start of the period, `Y-m-d`, or null.
	 *
	 * @var string|null
	 */
	public ?string $from = null;

	/**
	 * The end of the period, `Y-m-d`, or null.
	 *
	 * @var string|null
	 */
	public ?string $until = null;

	/**
	 * Who searched.
	 *
	 * @var string
	 */
	public string $userId = '';
}//end class
