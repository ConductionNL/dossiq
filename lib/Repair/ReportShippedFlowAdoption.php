<?php

/**
 * Dossiq Report Shipped Flow Adoption Repair Step.
 *
 * The install-time voice of a gap the install cannot close by itself.
 *
 * dossiq ships two flows. OpenRegister imports them `enabled = false,
 * owner = NULL` on purpose, and a flow with no owner is never dispatched — the
 * FlowLocator says so and then says nothing else, in the log, where nobody
 * reading an install transcript will find it. So on a fresh install the whole
 * shipped chain is inert, and the install reports success.
 *
 * 🔴 THIS STEP DOES NOT ADOPT. It CANNOT: `FlowService::adopt()` writes the
 * calling user's uid and refuses when there is no acting user, and a repair step
 * runs as nobody. Nor should it want to — adoption is a person volunteering to
 * have runs execute as them, and an upgrade is not that person. What this step
 * can do is make the outstanding act LOUD at the moment an administrator is
 * looking at the output, and name the exact command that completes it.
 *
 * Never throws and never fails the install: an unarmed flow is a to-do, not a
 * broken instance. It runs at install and at every upgrade, so the reminder
 * keeps appearing until somebody acts on it, and disappears by itself when they
 * do.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\ShippedFlowAdoptionInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reports which shipped flows still need an owner before they can dispatch.
 *
 * @spec openspec/specs/case-management/spec.md
 */
class ReportShippedFlowAdoption implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param ShippedFlowAdoptionInterface $adoption Reads what the shipped flows look like.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly ShippedFlowAdoptionInterface $adoption,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the repair-step display name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function getName(): string {
		return 'Report which of Dossiq\'s shipped flows still need to be adopted';
	}//end getName()

	/**
	 * Report the outstanding adoptions.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function run(IOutput $output): void {
		$census = $this->readCensus();
		if ($census === null) {
			return;
		}

		if ($this->censusHasFlows(output: $output, census: $census) === false) {
			return;
		}

		$pending = $this->adoption->outstanding($census['flows']);
		if ($pending === []) {
			$output->info(
				'Dossiq: all ' . count($census['flows']) . ' shipped flow(s) are adopted and enabled.'
			);
			return;
		}

		$this->reportPending(output: $output, pending: $pending, total: count($census['flows']));
	}//end run()

	/**
	 * Census the shipped flows.
	 *
	 * @return array<string, mixed>|null The census, or null when it could not be produced.
	 */
	private function readCensus(): ?array {
		try {
			$census = $this->adoption->census();
		} catch (Throwable $e) {
			// A report that cannot be produced must not fail an install.
			$this->logger->warning('Dossiq: could not census the shipped flows: ' . $e->getMessage());
			return null;
		}

		return $census;
	}//end readCensus()

	/**
	 * Whether the census is available and holds flows; reports when it does not.
	 *
	 * @param IOutput              $output Output sink.
	 * @param array<string, mixed> $census The census.
	 *
	 * @return bool
	 */
	private function censusHasFlows(IOutput $output, array $census): bool {
		if ($census['available'] === false) {
			$output->info('Dossiq: ' . $census['note']);
			return false;
		}

		if ($census['flows'] === []) {
			// Distinct from "all adopted": no flows stored at all means the
			// register import has not run or did not carry them, which is a
			// different problem with a different fix.
			$output->warning(
				'Dossiq: no shipped flows are stored. They are imported when the register is; '
				. 're-run `occ maintenance:repair` and check the log for [SchemaFlowImport].'
			);
			return false;
		}

		return true;
	}//end censusHasFlows()

	/**
	 * Report each flow that still awaits adoption, and how to adopt them.
	 *
	 * @param IOutput                          $output  Output sink.
	 * @param array<int, array<string, mixed>> $pending The flows still to adopt.
	 * @param int                              $total   The number of shipped flows.
	 *
	 * @return void
	 */
	private function reportPending(IOutput $output, array $pending, int $total): void {
		foreach ($pending as $flow) {
			$output->warning(
				'Dossiq: shipped flow "' . $flow['name'] . '" ' . $this->reasonFor(flow: $flow)
				. ', so it will not run.'
			);
		}

		$output->warning(
			'Dossiq: complete the adoption with: occ dossiq:flows:adopt --user <admin> --enable '
			. '(flows arrive ownerless and disabled by OpenRegister\'s design; adopting one is a '
			. 'deliberate act, because its runs then execute as that user).'
		);

		// 🔴 THE LOG LINE CARRIES THE COMMAND, BECAUSE ON ONE PATH IT IS THE
		// ONLY WITNESS. Everything above goes to `IOutput`, and `IOutput` for a
		// repair step is a set of dispatched events. Only `occ
		// maintenance:repair`, `occ upgrade` and the web updater subscribe to
		// them; `occ app:enable dossiq` runs the very same steps and listens to
		// nothing, so a docker install prints not one of these warnings.
		// Reporting "two flows need you" into a log with no way to act on it
		// leaves the reader exactly where they started, so the fix goes in the
		// line itself. docs/admin/flows.md says which path shows what.
		$this->logger->warning(
			'Dossiq: shipped flows await adoption',
			[
				'app' => AppIdentity::APP_ID,
				'pending' => count($pending),
				'total' => $total,
				'command' => 'occ dossiq:flows:adopt --user <admin> --enable',
			]
		);
	}//end reportPending()

	/**
	 * Say which of the two things is missing, rather than that something is.
	 *
	 * @param array{uuid: string, name: string, enabled: bool, owner: string} $flow The census row.
	 *
	 * @return string The reason clause.
	 */
	private function reasonFor(array $flow): string {
		if ($flow['owner'] === '' && $flow['enabled'] === false) {
			return 'has no owner and is disabled';
		}

		if ($flow['owner'] === '') {
			return 'has no owner';
		}

		return 'is disabled';
	}//end reasonFor()
}//end class
