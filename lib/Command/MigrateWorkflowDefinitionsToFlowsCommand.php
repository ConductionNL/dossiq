<?php

/**
 * Dossiq dossiq:actions:migrate-to-flows command.
 *
 * Projects stored `automaticAction` objects onto OpenRegister flows, so the
 * configuration becomes executable. It is a command rather than a repair step
 * because `FlowService` refuses to create a flow without a signed-in owner and
 * an active organisation, and an upgrade runs as nobody — see
 * {@see \OCA\Dossiq\Service\Workflow\WorkflowTemplateFlowMigrator}.
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
 * @spec openspec/specs/workflow-definitions-to-flow/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Service\Workflow\WorkflowTemplateFlowMigrator;
use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Project Dossiq workflow definitions onto OpenRegister flows.
 *
 * @spec openspec/specs/workflow-definitions-to-flow/spec.md
 */
class MigrateWorkflowDefinitionsToFlowsCommand extends Command {
	/**
	 * Wire the command against the migrator.
	 *
	 * @param WorkflowTemplateFlowMigrator $migrator The migrator.
	 * @param IUserManager $userManager Resolves the acting user.
	 */
	public function __construct(
		private readonly WorkflowTemplateFlowMigrator $migrator,
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
	 * @spec openspec/specs/workflow-definitions-to-flow/spec.md
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:workflows:migrate-to-flows')
			->setDescription(
				'Project Dossiq workflow definitions onto OpenRegister flows (idempotent). '
				. 'Per ADR-065 OpenRegister is the only home for a flow engine. The flows arrive '
				. 'DISABLED: the definitions still drive cases, and enabling a projection without '
				. 'retiring its definition would move every case twice. Use --dry-run first.'
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
	 * @spec openspec/specs/workflow-definitions-to-flow/spec.md
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$console = new MigrationConsole();
		$console->userManager = $this->userManager;
		$console->output = $output;
		$console->command = 'dossiq:workflows:migrate-to-flows';

		return $console->run(
			input: $input,
			migrate: fn (IUser $user, bool $dryRun): array => $this->migrator->migrate(user: $user, dryRun: $dryRun)
		);
	}//end execute()
}//end class
