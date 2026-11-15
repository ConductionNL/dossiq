<?php

/**
 * Contract of IntegrationMode
 *
 * The public surface of IntegrationMode that other components call, so they depend
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
 * Contract of IntegrationMode.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface IntegrationModeInterface {

	/**
	 * Dormant tier — no external call. Default for every seam.
	 */
	public const LOG = 'log';

	/**
	 * Hosted test-environment tier.
	 */
	public const TEST = 'test';

	/**
	 * Production tier (customer-side aansluiting).
	 */
	public const LIVE = 'live';

	/**
	 * Resolve the configured tier for an integration, defaulting to
	 * `log` (fail-closed to no external call) when unset or unknown.
	 *
	 * @param string $integration Integration name (e.g. `brp`, `kvk`, `digid`).
	 * @param array<string> $allowed The tiers this integration accepts.
	 *
	 * @return string One of the allowed tiers, or `log`.
	 *
	 * @spec openspec/specs/external-integration-test-wiring/spec.md
	 */
	public function resolve(string $integration, array $allowed): string;

	/**
	 * Read an integration string setting (e.g. baseUrl, apiKey).
	 *
	 * @param string $integration Integration name.
	 * @param string $key Setting suffix (e.g. `baseUrl`).
	 * @param string $default Fallback value.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/external-integration-test-wiring/spec.md
	 */
	public function setting(string $integration, string $key, string $default = ''): string;
}//end interface
