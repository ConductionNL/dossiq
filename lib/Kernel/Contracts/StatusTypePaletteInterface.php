<?php

/**
 * Contract of StatusTypePalette
 *
 * The public surface of StatusTypePalette that other components call, so they depend
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
 * Contract of StatusTypePalette.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StatusTypePaletteInterface {

	/**
	 * The colour a shipped status should take now, or null to leave it.
	 *
	 * A row takes the board colour while it holds no colour, the schema's
	 * grey default, or the colour dossiq shipped it with; any other colour was
	 * chosen here and stays.
	 *
	 * @param array<string, mixed> $row The stored status type.
	 *
	 * @return string|null The colour to write, or null.
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-a-shipped-status-takes-the-colour-its-boards-draw
	 */
	public function recolour(array $row): ?string;
}//end interface
