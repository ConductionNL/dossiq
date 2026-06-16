<?php

/**
 * Procest archief:retry-submissions command.
 *
 * Manual entry point that mirrors the 5-minute
 * {@see \OCA\Procest\BackgroundJob\ArchivalSubmissionRetryJob} — drains
 * the failed-submission queue, honouring the exponential backoff curve
 * (1m → 5m → 30m → 2h → 8h) and escalating attempts that hit the
 * 5-attempt threshold to the DIV audit channel.
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
 * @spec openspec/changes/archief-edepot-handover-05-sip-submission/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Command;

use OCA\Procest\Service\ArchivalSubmissionRetryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manually trigger the archief retry-queue drain.
 *
 * @spec openspec/changes/archief-edepot-handover-05-sip-submission/tasks.md
 */
class ArchiefRetrySubmissionsCommand extends Command
{
    /**
     * Wire the command against the retry service.
     *
     * @param ArchivalSubmissionRetryService $retryService Retry orchestrator.
     */
    public function __construct(
        private readonly ArchivalSubmissionRetryService $retryService,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Define command name + description.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(name: 'procest:archief:retry-submissions')
            ->setDescription('Drain the failed-submission queue (manual replay of the 5-minute background job).');
    }//end configure()

    /**
     * Execute the retry sweep and report counts.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int Symfony command exit code.
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $counts = $this->retryService->processRetryQueue();
        } catch (\Throwable $e) {
            $output->writeln('<error>Retry sweep failed: '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>archief:retry-submissions done</info>');
        foreach ($counts as $key => $value) {
            $output->writeln('  '.$key.' = '.$value);
        }

        return Command::SUCCESS;
    }//end execute()
}//end class
