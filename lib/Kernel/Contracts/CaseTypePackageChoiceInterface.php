<?php

/**
 * Contract of CaseTypePackageChoice
 *
 * The public surface of CaseTypePackageChoice that other components call, so they depend
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
 * Contract of CaseTypePackageChoice.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTypePackageChoiceInterface {

	/**
	 * Install the package a card names and record the choice.
	 *
	 * Installed and already-current both count. A refusal or a failed write
	 * stores nothing, so the step stays open.
	 *
	 * @param string $id The package id the card posted.
	 *
	 * @return array{success: bool, message: string, detail?: array<string, mixed>} The outcome.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function install(string $id): array;

	/**
	 * Whether the step has been dealt with: a choice is stored, or an
	 * on-request package is already installed.
	 *
	 * @return bool True when the step is done.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function isDecided(): bool;

	/**
	 * Whether a package can be installed on request.
	 *
	 * @param string $id The package id.
	 *
	 * @return bool True for a valid on-request package.
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md#requirement-an-administrator-installs-a-shipped-package
	 */
	public function isOffered(string $id): bool;

	/**
	 * The cards the step offers: none first, then every valid on-request package.
	 *
	 * @return array<int, array{id: string, label: string, description: string, icon: string}> The options.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function options(): array;

	/**
	 * The packages the marketplace lists: every valid package with a summary.
	 *
	 * @return array<int, array{id: string, title: string, version: string, summary: string, installed: string, onRequest: bool}> The packages.
	 *
	 * @spec openspec/specs/dossiq-store-surface/spec.md#requirement-the-marketplace-lists-the-shipped-packages
	 */
	public function shipped(): array;

	/**
	 * Write the example rows of the packages installed before example data
	 * was chosen.
	 *
	 * Called once the example data decision is recorded. The dataset itself
	 * is in by then, so a failure here is answered as an outcome beside it
	 * rather than thrown.
	 *
	 * @return array<int, array<string, mixed>> One outcome per installed package.
	 *
	 * @spec openspec/changes/package-examples/specs/case-types/spec.md
	 */
	public function writeExamplesOfInstalled(): array;
}//end interface
