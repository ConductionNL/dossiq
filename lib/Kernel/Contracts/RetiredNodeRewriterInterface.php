<?php

/**
 * Contract of RetiredNodeRewriter
 *
 * The public surface of RetiredNodeRewriter that other components call, so they depend
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
 * Contract of RetiredNodeRewriter.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RetiredNodeRewriterInterface {

	/**
	 * Rewrite a graph.
	 *
	 * @param array<int, mixed> $nodes The flow's nodes.
	 * @param array<int, mixed> $edges The flow's edges.
	 *
	 * @return array{
	 *     nodes: array<int, mixed>,
	 *     edges: array<int, mixed>,
	 *     changes: array<int, array{step: string, type: string, outcome: string, replacement: string|null, reason: string, orphaned: bool}>
	 * } The rewritten graph and one row per retired step. `outcome` is `replaced`,
	 *   `removed` or `unmappable`; an unmappable step is still in the graph. No rows
	 *   means nothing was retired.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function rewrite(array $nodes, array $edges): array;
}//end interface
