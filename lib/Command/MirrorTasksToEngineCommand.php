<?php

/**
 * Dossiq dossiq:tasks:mirror command.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category  Command
 * @package   OCA\Dossiq\Command
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Service\Task\TaskBackfillService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Copy existing `caseTask` rows into OpenRegister's task engine.
 *
 * The mirror in {@see \OCA\Dossiq\Service\Task\EngineTaskGateway} catches
 * tasks as they are created. This catches the ones that already existed, so
 * the two stores can be compared on the whole set rather than on whatever was
 * made since the flag went on.
 *
 * Idempotent and safe to re-run. `--dry-run` is the default posture for a
 * first look: it counts what WOULD be written and touches nothing.
 *
 * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
 */
class MirrorTasksToEngineCommand extends Command {

    /**
     * Wire the command against the backfill service.
     *
     * @param TaskBackfillService $backfill The backfill service.
     */
    public function __construct(
        private readonly TaskBackfillService $backfill,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Define command name, description and options.
     *
     * @return void
     */
    protected function configure(): void {
        $this->setName(name: 'dossiq:tasks:mirror')
            ->setDescription(description: 'Copy existing case tasks into OpenRegister\'s task engine (dual-run backfill)')
            ->addOption(
                name: 'dry-run',
                shortcut: null,
                mode: InputOption::VALUE_NONE,
                description: 'Count what would be written without writing anything'
            );
    }//end configure()

    /**
     * Run the backfill.
     *
     * Exits non-zero when the engine is unreachable, because a backfill that
     * silently wrote nothing is the failure this command exists to prevent.
     * A run that reads zero tasks is NOT an error: an instance may genuinely
     * have none, and the count says so.
     *
     * @param InputInterface  $input  The input.
     * @param OutputInterface $output The output.
     *
     * @return integer The exit code.
     *
     * @spec openspec/changes/dossiq-duplication-to-abstractions/tasks.md
     */
    protected function execute(InputInterface $input, OutputInterface $output): int {
        $dryRun = (bool)$input->getOption('dry-run');

        if ($dryRun === true) {
            $output->writeln('<comment>Dry run: nothing will be written.</comment>');
        }

        $result = $this->backfill->run(dryRun: $dryRun);

        if ($result['error'] !== '') {
            $output->writeln(sprintf('<error>%s</error>', $result['error']));

            return Command::FAILURE;
        }

        $verb = 'written';
        if ($dryRun === true) {
            $verb = 'would be written';
        }

        $output->writeln(
            sprintf(
                'Read %d task(s): %d %s, %d skipped (no case), %d failed.',
                $result['read'],
                $result['written'],
                $verb,
                $result['skipped'],
                $result['failed']
            )
        );

        if ($result['failed'] > 0) {
            $output->writeln('<error>Some tasks did not reach the engine. See the log.</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }//end execute()
}//end class
