<?php

/**
 * One flow step of a retired node type, with what the retirement map says of it
 *
 * The node as stored, its type and id, and the map's row for that type: what
 * RetiredNodeRewriter reads once per retired step and its translate, replace
 * and remove steps share.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

/**
 * One retired step; it never leaves the rewriter that built it.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */
final class RetiredStep {

	/**
	 * The node as stored.
	 *
	 * @var array<string, mixed>
	 */
	public array $node = [];

	/**
	 * The map's row for the node's type.
	 *
	 * @var array<string, mixed>
	 */
	public array $row = [];

	/**
	 * The node's type.
	 *
	 * @var string
	 */
	public string $type = '';

	/**
	 * The node's id.
	 *
	 * @var string
	 */
	public string $stepId = '';
}//end class
