<?php

/**
 * Contract of PortalContributionProvider
 *
 * The public surface of PortalContributionProvider that other components call, so they depend
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
 * Contract of PortalContributionProvider.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalContributionProviderInterface {

	/**
	 * The rule key a resident is told by when the decision on their Woo request is published.
	 *
	 * @var string
	 */
	public const RULE_WOO_REQUEST_PUBLISHED = 'dossiq.wooRequest.published';

	/**
	 * The case fields a citizen may see, for a surface that quotes a case back.
	 *
	 * The ontvangstbevestiging names what was received, so it reads this rather
	 * than deciding for itself what a citizen may be shown.
	 *
	 * @return array<int, string> The field names.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md
	 */
	public function citizenCaseFields(): array;
}//end interface
