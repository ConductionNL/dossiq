<?php

/**
 * Reports stored dossiq automatic actions of a retired type.
 *
 * Those are data somebody entered, so each one is named in the upgrade output
 * and the log and left exactly as it is. Split from RewriteRetiredFlowNodes,
 * which builds one report per run over the automatic action schema.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Names every stored automatic action of a retired type, and changes none of them.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */
final class RetiredActionReport {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param object          $objectService The OpenRegister object service.
	 * @param string          $register      The dossiq register.
	 * @param string          $schema        The automatic action schema.
	 * @param IOutput         $output        Output sink.
	 * @param LoggerInterface $logger        Records each action reported.
	 */
	public function __construct(
		private readonly object $objectService,
		private readonly string $register,
		private readonly string $schema,
		private readonly IOutput $output,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Report the stored actions of each retired type, type by type.
	 *
	 * @param array<string, string> $types The retired action types and why each is retired.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function report(array $types): void {
		foreach ($types as $type => $reason) {
			$this->reportType(type: (string)$type, reason: $reason);
		}
	}//end report()

	/**
	 * Name every stored action of one retired type.
	 *
	 * @param string $type   The retired type.
	 * @param string $reason Why it is retired.
	 *
	 * @return void
	 */
	private function reportType(string $type, string $reason): void {
		foreach ($this->actionsOfType(type: $type) as $row) {
			$identifier = (string)($row['slug'] ?? ($row['id'] ?? ($row['uuid'] ?? '?')));
			$message = 'Dossiq: automatic action "' . $identifier . '" has type ' . $type
				. ', which is retired (' . $reason . '). It is kept, but it will not run.';
			$this->output->warning($message);
			$this->logger->warning($message, ['app' => AppIdentity::APP_ID, 'action' => $identifier]);
		}
	}//end reportType()

	/**
	 * The stored actions of one type, read as the system; a read that fails is logged and reads as none.
	 *
	 * @param string $type The retired type.
	 *
	 * @return array<int|string, mixed> The action rows.
	 */
	private function actionsOfType(string $type): array {
		try {
			return (array)$this->runAsSystemIfAvailable(
				objectService: $this->objectService,
				operation: fn (): array => $this->searchActions(type: $type)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not read the stored automatic actions of a retired type',
				['app' => AppIdentity::APP_ID, 'type' => $type, 'exception' => $e->getMessage()]
			);
			return [];
		}
	}//end actionsOfType()

	/**
	 * Search the stored actions of one type, unscoped.
	 *
	 * @param string $type The retired type.
	 *
	 * @return array<int, array<string, mixed>> The action rows.
	 */
	private function searchActions(string $type): array {
		return $this->searchObjectsAsArraysUnscoped(
			objectService: $this->objectService,
			register: $this->register,
			schema: $this->schema,
			filters: ['type' => $type, '_limit' => 1000]
		);
	}//end searchActions()
}//end class
