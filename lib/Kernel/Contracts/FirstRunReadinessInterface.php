<?php

/**
 * Contract of FirstRunReadiness
 *
 * The public surface of FirstRunReadiness that other components call, so they depend
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
 * Contract of FirstRunReadiness.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FirstRunReadinessInterface {

	/**
	 * The tour steps that name a surface this app no longer has.
	 *
	 * A step naming a page that is gone is skipped in silence by the runner,
	 * which is how a tour quietly stops teaching half the product: nothing
	 * errors, nothing logs, the step simply never appears. So it is reported
	 * beside the readiness items, where somebody can see it.
	 *
	 * Only `page` and `nav-item` targets can be judged from here: an `element`
	 * target names a DOM test id, which no manifest resolves, so those are
	 * reported as unverifiable rather than counted as broken. Judging them
	 * belongs in the runner, which can see the DOM.
	 *
	 * @param array<string, mixed>|null $manifest The manifest to judge, or null
	 *        for the shipped one. Taken as an argument so a test can hand it a
	 *        tour that DOES name a missing page: a scan that only ever runs
	 *        over a healthy tree reports the same green as one that works.
	 *
	 * @return array<int, array<string, mixed>> The broken and unverifiable steps.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function brokenTourSteps(?array $manifest = null): array;

	/**
	 * Every declared item, with whether it is done and why not.
	 *
	 * @return array<int, array<string, mixed>> The reported items.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function report(): array;
}//end interface
