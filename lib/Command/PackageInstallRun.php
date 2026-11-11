<?php

/**
 * Dossiq package install run.
 *
 * What one `occ dossiq:packages:install` run shares between its steps.
 *
 * @category Command
 * @package  OCA\Dossiq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Service\CaseDefinition\PackageInstaller;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One install run: the installer it reads, what it installs, how, and where it reports.
 *
 * Built by {@see InstallPackageCommand} only; it never leaves it.
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
final class PackageInstallRun {

	/**
	 * The installer, over the shipped packages or the given root.
	 *
	 * @var PackageInstaller
	 */
	public PackageInstaller $installer;

	/**
	 * The package ids to install.
	 *
	 * @var array<int, string>
	 */
	public array $ids = [];

	/**
	 * Whether rows this instance changed are overwritten.
	 *
	 * @var bool
	 */
	public bool $overLocalChanges = false;

	/**
	 * The console.
	 *
	 * @var OutputInterface
	 */
	public OutputInterface $output;
}//end class
