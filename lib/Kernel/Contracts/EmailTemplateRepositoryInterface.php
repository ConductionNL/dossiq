<?php

/**
 * Contract of EmailTemplateRepository
 *
 * The public surface of EmailTemplateRepository that other components call, so they depend
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

/**
 * Contract of EmailTemplateRepository.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmailTemplateRepositoryInterface {

	/**
	 * Persist (insert OR update) a template payload via OpenRegister.
	 *
	 * @param array<string, mixed> $payload Template fields.
	 *
	 * @return array<string, mixed> The saved object, or the payload when OR
	 *                              returns an unusable shape.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or the
	 *                          emailTemplate schema is not configured.
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T04
	 */
	public function saveTemplate(array $payload): array;
}//end interface
