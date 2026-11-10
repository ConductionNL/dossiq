<?php

/**
 * Dossiq dossiq:actions:migrate-to-flows command.
 *
 * Projects stored `automaticAction` objects onto OpenRegister flows, so the
 * configuration becomes executable. It is a command rather than a repair step
 * because `FlowService` refuses to create a flow without a signed-in owner and
 * an active organisation, and an upgrade runs as nobody — see
 * {@see \OCA\Dossiq\Service\Actions\AutomaticActionFlowMigrator}.
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
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Service\Actions\AutomaticActionFlowMigrator;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Migrate Dossiq automatic actions to OpenRegister flows.
 *
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */
class MigrateAutomaticActionsToFlowsCommand extends Command {
	/**
	 * Wire the command against the migrator.
	 *
	 * @param AutomaticActionFlowMigrator $migrator The migrator.
	 * @param IUserManager $userManager Resolves the acting user.
	 */
	public function __construct(
		private readonly AutomaticActionFlowMigrator $migrator,
		private readonly IUserManager $userManager,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define the command name, description and options.
	 *
	 * `--user` is REQUIRED and has no default. The created flows inherit that
	 * user's identity and organisation permanently, so guessing an owner would
	 * hand every migrated flow to whoever the guess landed on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/tasks.md
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:actions:migrate-to-flows')
			->setDescription(
				'Migrate Dossiq automatic actions to OpenRegister flows (idempotent). '
				. 'The flows are created ENABLED, so actions that never fired before will start firing — '
				. 'use --dry-run first. Each action becomes the step of the app that owns it: mail and '
				. 'notifications OpenRegister, documents Filinq. A webhook action has no such step and '
				. 'is skipped with the reason.'
			);

		(new MigrationConsole())->addOptions(command: $this);
	}//end configure()

	/**
	 * Run the migration and report per-action outcomes.
	 *
	 * @param InputInterface $input Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Symfony command exit code.
	 *
	 * @spec openspec/changes/page-topology-cleanup/tasks.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$console = new MigrationConsole();
		$console->userManager = $this->userManager;
		$console->output = $output;
		$console->command = 'dossiq:actions:migrate-to-flows';

		return $console->run(
			input: $input,
			migrate: fn (IUser $user, bool $dryRun): array => $this->migrator->migrate(user: $user, dryRun: $dryRun)
		);
	}//end execute()
}//end class
