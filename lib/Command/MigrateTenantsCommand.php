<?php

/**
 * Procest procest:migrate-tenants command.
 *
 * One-shot, idempotent migration of legacy procest `tenant` schema objects onto
 * OpenRegister Organisations (`migrate-tenant-to-or-tenant`, ADR-022). Reads any
 * pre-existing `tenant` rows, projects each onto an OR Organisation (preserving
 * the row UUID + lifecycle status), and reports a migrated/skipped/failed
 * summary. Safe to re-run — Organisations whose slug already exists are skipped.
 *
 * @category Command
 * @package  OCA\Procest\Command
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Command;

use OCA\Procest\Service\TenantMigrationService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Migrate legacy procest `tenant` objects to OR Organisations.
 *
 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
 */
class MigrateTenantsCommand extends Command {
	/**
	 * Wire the command against the migration service.
	 *
	 * @param TenantMigrationService $migrationService Tenant → Organisation migrator.
	 */
	public function __construct(
		private readonly TenantMigrationService $migrationService,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define command name + description.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
	 */
	protected function configure(): void {
		$this->setName(name: 'procest:migrate-tenants')
			->setDescription('Migrate legacy procest tenant objects to OpenRegister Organisations (idempotent).');
	}//end configure()

	/**
	 * Execute the migration and report counts.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/changes/migrate-tenant-to-or-tenant/tasks.md
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		try {
			$summary = $this->migrationService->migrate();
		} catch (\Throwable $e) {
			$output->writeln('<error>Tenant migration failed: ' . $e->getMessage() . '</error>');
			return Command::FAILURE;
		}

		$output->writeln('<info>procest:migrate-tenants done</info>');
		$output->writeln('  total    = ' . $summary['total']);
		$output->writeln('  migrated = ' . $summary['migrated']);
		$output->writeln('  skipped  = ' . $summary['skipped']);
		$output->writeln('  failed   = ' . $summary['failed']);

		foreach ($summary['mappings'] as $mapping) {
			$output->writeln('  ' . $mapping['tenant'] . ' -> ' . $mapping['organisation']);
		}

		if ($summary['failed'] > 0) {
			return Command::FAILURE;
		}

		return Command::SUCCESS;
	}//end execute()
}//end class
