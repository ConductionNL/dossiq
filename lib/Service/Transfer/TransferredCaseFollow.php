<?php

/**
 * Dossiq transferred case follow
 *
 * Lets the organisation that handed a case over follow it: each act recorded
 * on the case after its transfer was accepted is appended to that transfer's
 * sender activity, which the sending organisation reads.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transfer
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
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transfer;

use OCA\Dossiq\Service\CaseTransfer\TransferStore;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Appends an act on a handed-over case to the sender activity of its accepted transfer.
 *
 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/federated-case-collaboration/spec.md
 */
class TransferredCaseFollow {

	/**
	 * The acts a sender follows, as the transfer schema names them.
	 *
	 * @var array<int, string>
	 */
	public const ACTS = [
		'document added',
		'contact moment added',
		'note added',
		'task completed',
		'status changed',
	];

	/**
	 * The most entries one transfer keeps; the oldest go first.
	 *
	 * @var int
	 */
	public const MAX_ENTRIES = 500;

	/**
	 * Constructor.
	 *
	 * @param TransferRegisterGateway $gateway OpenRegister and the transfer register.
	 * @param LoggerInterface         $logger  The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly TransferRegisterGateway $gateway,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record an act on a case for the organisation that handed it over.
	 *
	 * Nothing is recorded for a case that was never handed to another
	 * organisation, for an act this follow does not name, or when OpenRegister
	 * or the transfer register is not there. A team handover inside this
	 * organisation is not followed: the sending team stays in the same
	 * organisation and reads the case's own history.
	 *
	 * @param string $caseId The case the act was on.
	 * @param string $act    One of {@see self::ACTS}.
	 * @param string $actor  Who did it, or an empty string for the system.
	 *
	 * @return bool Whether the act was recorded.
	 *
	 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/federated-case-collaboration/spec.md#requirement-a-transfer-hands-the-case-over-and-the-sender-follows-its-activity-req-crs-42
	 */
	public function record(string $caseId, string $act, string $actor = ''): bool {
		$caseId = trim($caseId);
		if ($caseId === '' || in_array($act, self::ACTS, true) === false) {
			return false;
		}

		$store = $this->store();
		if ($store === null) {
			return false;
		}

		try {
			$transfer = $this->acceptedTransfer(store: $store, caseId: $caseId);
			if ($transfer === null) {
				return false;
			}

			$store->save(transfer: $this->withEntry(transfer: $transfer, act: $act, actor: $actor));
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not record an act for the organisation that handed the case over',
				['caseId' => $caseId, 'act' => $act, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end record()

	/**
	 * The transfer register, or null when OpenRegister or the register is not there.
	 *
	 * @return TransferStore|null
	 */
	private function store(): ?TransferStore {
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return null;
		}

		[$register, $schema] = $this->gateway->transferScope();
		$store = new TransferStore(objectService: $objectService, register: $register, schema: $schema);
		if ($store->isConfigured() === false) {
			return null;
		}

		return $store;
	}//end store()

	/**
	 * The most recently accepted organisation transfer of the case, or null.
	 *
	 * @param TransferStore $store  The transfer register.
	 * @param string        $caseId The case.
	 *
	 * @return array<string, mixed>|null The transfer's fields.
	 */
	private function acceptedTransfer(TransferStore $store, string $caseId): ?array {
		$matches = $store->objectService->findAll(
			[
				'filters' => [
					'register' => $store->register,
					'schema' => $store->schema,
					'caseId' => $caseId,
					'status' => 'accepted',
				],
			]
		);

		$latest = null;
		foreach ((array)$matches as $match) {
			$transfer = $this->fields(match: $match);
			if ($this->isOrganisationHandover(transfer: $transfer, caseId: $caseId) === false) {
				continue;
			}

			if ($latest === null || (string)($transfer['completedAt'] ?? '') > (string)($latest['completedAt'] ?? '')) {
				$latest = $transfer;
			}
		}

		return $latest;
	}//end acceptedTransfer()

	/**
	 * The fields of a found transfer, whether OpenRegister answered an object or an array.
	 *
	 * @param mixed $match The found transfer.
	 *
	 * @return array<string, mixed>
	 */
	private function fields(mixed $match): array {
		if (is_object($match) === true && method_exists($match, 'jsonSerialize') === true) {
			return (array)$match->jsonSerialize();
		}

		return (array)$match;
	}//end fields()

	/**
	 * Whether an accepted transfer handed this case to another organisation, for this case.
	 *
	 * A filter OpenRegister ignored would hand back another case's transfer
	 * or one still pending, so both are checked again here.
	 *
	 * @param array<string, mixed> $transfer The transfer's fields.
	 * @param string               $caseId   The case the act was on.
	 *
	 * @return bool
	 */
	private function isOrganisationHandover(array $transfer, string $caseId): bool {
		if ((string)($transfer['status'] ?? '') !== 'accepted' || (string)($transfer['caseId'] ?? '') !== $caseId) {
			return false;
		}

		return (string)($transfer['handoverScope'] ?? 'organisation') !== 'team';
	}//end isOrganisationHandover()

	/**
	 * The transfer with the act appended to its sender activity, keeping the newest entries.
	 *
	 * @param array<string, mixed> $transfer The transfer's fields.
	 * @param string               $act      The act.
	 * @param string               $actor    Who did it.
	 *
	 * @return array<string, mixed>
	 */
	private function withEntry(array $transfer, string $act, string $actor): array {
		$activity = array_values((array)($transfer['senderActivity'] ?? []));
		$activity[] = ['act' => $act, 'actor' => $actor, 'recordedAt' => date('c')];

		$transfer['senderActivity'] = array_slice($activity, -self::MAX_ENTRIES);

		return $transfer;
	}//end withEntry()
}//end class
