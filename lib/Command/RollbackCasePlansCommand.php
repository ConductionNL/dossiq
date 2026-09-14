<?php

/**
 * Dossiq `dossiq:cmmn:rollback-case-plans` command.
 *
 * The R1 rollback of retire-cmmn-caseplanstate (design.md section 4): rebuild
 * a `casePlanState` blob from the rows OpenRegister holds, for cases that no
 * longer have one.
 *
 * IT IS THE SECOND HALF OF A ROLLBACK, NOT THE WHOLE OF IT. Rolling back is
 * `occ config:app:set dossiq cmmn_prefer_openregister_case_plan --value no`
 * first, which sends the panel back to the local engine for every case that
 * still carries a blob, and this command after it for the cases that do not.
 * Running this alone changes nothing a caseworker can see: with the preference
 * still on, the panel keeps reading rows.
 *
 * It never deletes a row, so rolling forward again is flipping the flag back.
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
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Service\CasePlanRollbackService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Regenerate `casePlanState` from OpenRegister's plan-item rows.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
class RollbackCasePlansCommand extends Command {

	/**
	 * Wire the command against the reverse projection.
	 *
	 * @param CasePlanRollbackService $rollback The reverse projection.
	 */
	public function __construct(
		private readonly CasePlanRollbackService $rollback,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Define the command name, description and options.
	 *
	 * The case uuids are ARGUMENTS and at least one is required. There is no
	 * "every case" mode on purpose: a blanket run would write a blob onto every
	 * case that has rows, including the ones that never had one, and rolling
	 * back is a targeted act taken under pressure at a known set of cases.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:cmmn:rollback-case-plans')
			->setDescription(
				'Rebuild a case `casePlanState` blob from the plan-item rows OpenRegister holds. '
				. 'The second half of the bridge rollback: set '
				. '`cmmn_prefer_openregister_case_plan` to `no` first, or nothing a caseworker sees changes. '
				. 'Rows are never deleted, so rolling forward again is flipping that flag back.'
			)
			->addArgument(
				name: 'case',
				mode: (InputArgument::IS_ARRAY | InputArgument::REQUIRED),
				description: 'One or more case uuids to rebuild a blob for.'
			)
			->addOption(
				name: 'dry-run',
				mode: InputOption::VALUE_NONE,
				description: 'Report what would be written, and write nothing.'
			);
	}//end configure()

	/**
	 * Run the reverse projection over the named cases.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return integer 0 when every named case was rebuilt, 1 otherwise.
	 *
	 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$dryRun = ($input->getOption('dry-run') === true);
		$cases = $input->getArgument('case');
		if (is_array($cases) === false) {
			$cases = [];
		}

		$failed = 0;

		foreach ($cases as $caseId) {
			$result = $this->reportFor(caseId: (string)$caseId, dryRun: $dryRun);

			$verdict = '<comment>skipped</comment>';
			if ($result['written'] === true) {
				$verdict = '<info>rebuilt</info>';
			}

			$output->writeln(
				sprintf('%s %s (%d items): %s', $verdict, $result['caseId'], $result['items'], $result['reason'])
			);

			// A dry run reports and is not a failure; anything else that did
			// not write is one, so a rollback run cannot exit 0 while leaving
			// a case the operator asked for without a blob.
			if ($result['written'] === false && $result['reason'] !== 'dry_run') {
				$failed++;
			}
		}

		if ($failed > 0) {
			$output->writeln(sprintf('<error>%d case(s) were not rebuilt.</error>', $failed));

			return 1;
		}

		return 0;
	}//end execute()

	/**
	 * Preview or rebuild one case, per the `--dry-run` option.
	 *
	 * The service offers two methods rather than one boolean, so the choice is
	 * made once, here, where the option is read.
	 *
	 * @param string  $caseId The case object uuid.
	 * @param boolean $dryRun Whether the operator asked for a dry run.
	 *
	 * @return array<string, mixed> What the service did.
	 */
	private function reportFor(string $caseId, bool $dryRun): array {
		if ($dryRun === true) {
			return $this->rollback->previewCase(caseId: $caseId);
		}

		return $this->rollback->rollbackCase(caseId: $caseId);
	}//end reportFor()
}//end class
