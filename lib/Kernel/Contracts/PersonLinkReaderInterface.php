<?php

/**
 * Contract of PersonLinkReader
 *
 * The public surface of PersonLinkReader that other components call, so they depend
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
 * Contract of PersonLinkReader.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PersonLinkReaderInterface {

	/**
	 * The email address a link carries, '' when it has none.
	 *
	 * @param array<string, mixed> $link The link.
	 *
	 * @return string The address.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
	 */
	public function emailOf(array $link): string;

	/**
	 * The name to show for a link: its display name, else its uid.
	 *
	 * @param array<string, mixed> $link The link.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
	 */
	public function nameOf(array $link): string;

	/**
	 * The people linked to a case, [] when OpenRegister cannot answer.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<int, array<string, mixed>> The links.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-005-a-file-request-shall-be-addressed-to-a-party-of-the-case
	 */
	public function peopleOn(string $caseId): array;
}//end interface
