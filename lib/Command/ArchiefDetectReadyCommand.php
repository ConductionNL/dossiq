<?php

/**
 * Procest archief:detect-ready command.
 *
 * Manual entry point that mirrors the nightly
 * {@see \OCA\Procest\BackgroundJob\ArchivalTriggerScanJob} — walks all
 * closed cases and asks ArchivalTriggerService to refresh their
 * OverdrachtTrigger row (ready / blocked / suspended). Used by DIV
 * operators during onboarding ("rerun the sweep after I added a
 * BewaarTermijnRegel") and by ops verification.
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
 * @spec openspec/changes/archief-edepot-handover-02-retention-trigger/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Command;

use OCA\Procest\Service\ArchivalTriggerService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Support\SearchesObjects;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manually trigger the archief detection sweep.
 *
 * @spec openspec/changes/archief-edepot-handover-02-retention-trigger/tasks.md
 */
class ArchiefDetectReadyCommand extends Command
{
    use SearchesObjects;

    /**
     * Wire the command against the trigger + settings services.
     *
     * @param ArchivalTriggerService $archivalService Detection service.
     * @param SettingsService        $settingsService Settings + ObjectService.
     */
    public function __construct(
        private readonly ArchivalTriggerService $archivalService,
        private readonly SettingsService $settingsService,
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
        $this->setName(name: 'procest:archief:detect-ready')
            ->setDescription('Walk every closed case and refresh its OverdrachtTrigger row (manual nightly-sweep replay).');
    }//end configure()

    /**
     * Execute the detection sweep and report counts.
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
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $caseSchema    = (string) $this->settingsService->getConfigValue('case_schema');
        if ($objectService === null || $register === '' || $caseSchema === '') {
            $output->writeln('<error>OpenRegister case schema is not configured — aborting.</error>');
            return Command::FAILURE;
        }

        try {
            $closed = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $caseSchema,
                filters: ['status' => 'closed'],
            );
        } catch (\Throwable $e) {
            $output->writeln('<error>Closed-case query failed: '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }

        try {
            $counts = $this->archivalService->detectReadyCases((array) $closed);
        } catch (\Throwable $e) {
            $output->writeln('<error>Detection sweep failed: '.$e->getMessage().'</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>archief:detect-ready done</info>');
        foreach ($counts as $key => $value) {
            $output->writeln('  '.$key.' = '.$value);
        }

        return Command::SUCCESS;
    }//end execute()
}//end class
