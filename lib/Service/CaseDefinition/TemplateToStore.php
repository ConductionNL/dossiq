<?php

/**
 * One decoded workflow template on its way into the register, with the id the package gives it and whether this instance already holds it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

/**
 * One decoded workflow template on its way into the register, with the id the package gives it and whether this instance already holds it.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class TemplateToStore {
	/**
	 * Constructor.
	 *
	 * @param string $name The archive entry, for the message
	 * @param array<string, mixed> $template The decoded template
	 * @param string $packageId The id the package gives it, or ''
	 * @param bool $conflict Whether this instance already holds it
	 */
	public function __construct(
		public readonly string $name,
		public readonly array $template,
		public readonly string $packageId,
		public readonly bool $conflict,
	) {
	}//end __construct()
}//end class
