<?php

/**
 * Contract of ActiveOrganisationProfile
 *
 * The public surface of ActiveOrganisationProfile that other components call, so they depend
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
 * Contract of ActiveOrganisationProfile.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ActiveOrganisationProfileInterface {

	/**
	 * The wizard field that holds the organisation's name.
	 *
	 * @var string
	 */
	public const NAME_FIELD = 'organisation_name';

	/**
	 * The wizard field that holds the address of the organisation's logo.
	 *
	 * @var string
	 */
	public const LOGO_FIELD = 'organisation_logo';

	/**
	 * Whether the wizard's Organisation step is settled: the active
	 * organisation has a name, or there is no organisation to name.
	 *
	 * @return bool True when settled.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function isSettled(): bool;

	/**
	 * Write the fields the wizard posted onto the active organisation.
	 *
	 * The wizard posts every field of the step, a blank one included, and has
	 * no current value to show in it. A blank field therefore keeps what the
	 * organisation has rather than erasing it. Only the active organisation is
	 * written; no id from the request is read. A logo that is not an http(s)
	 * URL is refused.
	 *
	 * @param array<string, mixed> $fields The posted fields, by wizard field name.
	 *
	 * @return bool True when there was nothing to write or the write succeeded.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function write(array $fields): bool;
}//end interface
