<?php

/**
 * Contract of RecordTemplateReader
 *
 * The public surface of RecordTemplateReader that other components call, so they depend
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

use RuntimeException;
use Throwable;

/**
 * Contract of RecordTemplateReader.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecordTemplateReaderInterface {

	/**
	 * One template of a schema, when the signed-in caller may use it.
	 *
	 * @param string $uuid   The template uuid.
	 * @param string $schema The schema id the template must be for, as OpenRegister stores it.
	 *
	 * @return array{uuid: string, name: string, values: array<string, mixed>}|null The template, null when
	 *                                                                              nobody is signed in, it is
	 *                                                                              absent, not usable by the
	 *                                                                              caller, or for another schema.
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 * @throws Throwable        When OpenRegister fails for any reason but "no such template".
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/record-template-start/spec.md#requirement-a-named-record-template-is-read-as-the-caller-req-rts-001
	 */
	public function find(string $uuid, string $schema): ?array;
}//end interface
