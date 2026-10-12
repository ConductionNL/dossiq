<?php

/**
 * Dossiq occ dossiq:woo:verify-delivered-set
 *
 * Recomputes the hashes of a delivered Woo set from the files as they are
 * now (woo-delivered-set-is-a-record REQ-WDS-003). The same service answers
 * the verify route on the case.
 *
 * @category Command
 * @package  OCA\Dossiq\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command;

use OCA\Dossiq\Woo\WooDeliveredSetVerifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Verify one delivered set and print every item.
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
 */
class VerifyWooDeliveredSetCommand extends Command {

	/**
	 * Constructor.
	 *
	 * @param WooDeliveredSetVerifier $verifier Recomputes the hashes.
	 */
	public function __construct(
		private readonly WooDeliveredSetVerifier $verifier,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Name, description and argument.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
	 */
	protected function configure(): void {
		$this->setName(name: 'dossiq:woo:verify-delivered-set')
			->setDescription('Check that the files of a delivered Woo set are still the files that went out.')
			->addArgument(name: 'setId', mode: InputArgument::REQUIRED, description: 'The delivered set.');
	}//end configure()

	/**
	 * Verify and print.
	 *
	 * @param InputInterface  $input  Console input.
	 * @param OutputInterface $output Console output.
	 *
	 * @return int Success only when the set verifies.
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$set = $this->verifier->find(setId: (string)$input->getArgument('setId'));
		if ($set === null) {
			$output->writeln('<error>No delivered set with that id.</error>');
			return Command::FAILURE;
		}

		$result = $this->verifier->verify(set: $set);
		foreach ($result['items'] as $item) {
			$output->writeln($item['status'] . '  ' . $item['deliveredRef'] . '  expected ' . $item['expected'] . '  actual ' . $item['actual']);
		}

		$output->writeln('set hash expected ' . $result['setHash']['expected'] . '  actual ' . $result['setHash']['actual']);
		if ($result['verified'] === false) {
			$output->writeln('<error>The set does not verify.</error>');
			return Command::FAILURE;
		}

		$output->writeln('The set verifies: every file is the file that went out.');
		return Command::SUCCESS;
	}//end execute()
}//end class
