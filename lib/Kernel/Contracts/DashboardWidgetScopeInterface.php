<?php

/**
 * Contract of DashboardWidgetScope
 *
 * The public surface of DashboardWidgetScope that other components call, so they depend
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
 * Contract of DashboardWidgetScope.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DashboardWidgetScopeInterface {

	/**
	 * The payload this reader may receive.
	 *
	 * @param array<string, mixed> $payload The computed dashboard payload.
	 * @param string $userId The reader.
	 *
	 * @return array<string, mixed> The payload, narrowed.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-a-widget-answers-nothing-to-a-reader-who-may-not-see-it-req-wrd-02
	 */
	public function narrowFor(array $payload, string $userId): array;

	/**
	 * Which declared widgets this reader may see, keyed by widget id.
	 *
	 * 🔑 THE SAME VERDICT `narrowFor()` USES, ANSWERED AS A MAP. That class
	 * already decides per widget; what it did not do was let the PAGE ask, so
	 * a reader who may not see a tile still had the tile drawn, empty or
	 * erroring, over a payload that correctly carried none of its figures. The
	 * verdict is computed here rather than in the controller so there is one
	 * decision, not two that can disagree.
	 *
	 * IT IS NOT THE ENFORCEMENT AND MUST NOT BECOME IT. The data is withheld by
	 * `narrowFor()` and by the endpoints behind the other widgets; this only
	 * lays the page out. A reader who calls it learns which tiles they would be
	 * shown, which is a fact about them.
	 *
	 * @param string $userId The reader.
	 *
	 * @return array<string, bool> Widget id to whether it is drawn.
	 *
	 * @spec openspec/specs/dashboard/spec.md#requirement-a-widget-answers-nothing-to-a-reader-who-may-not-see-it-req-wrd-02
	 */
	public function visibilityFor(string $userId): array;
}//end interface
