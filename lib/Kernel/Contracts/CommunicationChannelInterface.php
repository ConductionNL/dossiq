<?php

/**
 * Contract of CommunicationChannel
 *
 * The public surface of CommunicationChannel that other components call, so they depend
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
 * Contract of CommunicationChannel.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CommunicationChannelInterface {

	/**
	 * The channel slugs, in the order the schema declares them.
	 *
	 * @var array<int, string>
	 */
	public const SLUGS = ['email', 'portal', 'post', 'website', 'zgw-api'];

	/**
	 * The case fields a value becomes: the slug, plus the original when it was
	 * not already a slug. Empty when there is no value at all.
	 *
	 * @param mixed $value The incoming value.
	 *
	 * @return array<string, string|null>
	 *
	 * @spec openspec/changes/portal-contact-channel-follows-the-resident/specs/portal-contribution/spec.md#requirement-a-case-s-communication-channel-is-a-slug-mapped-at-every-boundary-req-portal-017
	 */
	public function normalise(mixed $value): array;
}//end interface
