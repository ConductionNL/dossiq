<?php

/**
 * One rewrite of a flow graph's retired steps
 *
 * The nodes kept so far, the edges as they now stand, and the change
 * recorded per retired step: the progress RetiredNodeRewriter::rewrite()
 * builds node by node.
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
 * The progress of one rewrite; it never leaves the rewriter that built it.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */
final class NodeRewrite {

	/**
	 * The nodes kept, translated or renamed, in order.
	 *
	 * @var array<int, mixed>
	 */
	public array $kept = [];

	/**
	 * The edges as they now stand.
	 *
	 * @var array<int, mixed>
	 */
	public array $edges = [];

	/**
	 * One change per retired step.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $changes = [];
}//end class
