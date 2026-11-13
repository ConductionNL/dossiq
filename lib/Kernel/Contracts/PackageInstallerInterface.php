<?php

/**
 * Contract of PackageInstaller
 *
 * The public surface of PackageInstaller that other components call, so they depend
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
 * Contract of PackageInstaller.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PackageInstallerInterface {

	/**
	 * The package was written and recorded.
	 */
	public const INSTALLED = 'installed';

	/**
	 * The package is already at this version here; nothing was written.
	 */
	public const CURRENT = 'current';

	/**
	 * The package was not written, for a reason the package or instance gives.
	 */
	public const REFUSED = 'refused';

	/**
	 * The write failed and was rolled back.
	 */
	public const FAILED = 'error';

	/**
	 * Every package the directory holds, with its version, install mode and
	 * the version installed here.
	 *
	 * @return array<int, array{id: string, title: string, version: string, summary: string, install: string, installed: string, valid: bool}>
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function catalogue(): array;

	/**
	 * Install one package.
	 *
	 * A row somebody changed here is kept.
	 *
	 * @param string $id The package id.
	 *
	 * @return array<string, mixed> status, package, version and message; errors
	 *         when refused as invalid, missing when a capability is missing,
	 *         written, skipped and refreshed counts and the ids of the kept rows
	 *         when installed.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function install(string $id): array;

	/**
	 * Install every package that is installed here or installs automatically.
	 *
	 * Each goes through install(), so one already at the shipped version
	 * writes nothing and a newer one refreshes the rows nobody edited. An
	 * on-request package that was never installed is left alone.
	 *
	 * @return array<int, array<string, mixed>> One outcome per package, in id order.
	 *
	 * @spec openspec/specs/case-types/spec.md#requirement-an-administrator-reloads-the-shipped-templates
	 */
	public function reloadInstalled(): array;

	/**
	 * An installer reading packages from another directory.
	 *
	 * @param string $root The directory that holds the packages.
	 *
	 * @return self The installer.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	public function fromRoot(string $root): self;

	/**
	 * Install one package, and give every row it shipped that somebody changed
	 * here the keys the package ships; keys it does not ship keep their value.
	 * Installs a package that is current too.
	 *
	 * 🔑 A SECOND NAMED ACT RATHER THAN A FLAG, as
	 * ShippedConfigurationService::adoptOverLocalChange(): an administrator's
	 * work goes, and the caller picks that by name.
	 *
	 * @param string $id The package id.
	 *
	 * @return array<string, mixed> As install().
	 *
	 * @spec openspec/changes/package-reinstall-applies-changed-content/specs/case-types/spec.md
	 */
	public function installOverLocalChanges(string $id): array;
}//end interface
