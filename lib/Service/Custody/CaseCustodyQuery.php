<?php

/**
 * Reading the chain of custody back: who held a case, and what a unit held.
 *
 * The two questions this record exists for. Both are answered from the holdings
 * themselves and never from the audit diff, which is the distinction D-1 draws:
 * the audit says what changed, the chain says who held it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Custody
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Custody;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Who held this case on a date, and which cases a unit held in a period.
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */
class CaseCustodyQuery {

	use SearchesObjects;

	/**
	 * How many holdings one unit read takes at most.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param CaseCustodyChain $chain           The chain the answers are read from.
	 * @param SettingsService  $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface  $logger          Records a read that could not be answered.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly CaseCustodyChain $chain,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The holding that covered a case on a moment, or null.
	 *
	 * Exactly one holding covers any moment the case existed, because a move
	 * closes one holding and opens the next at the SAME moment. The boundary is
	 * therefore half-open: the holding that began at that instant owns it, and
	 * the one that ended does not, so a transfer date never returns two units.
	 *
	 * @param string $caseId The case.
	 * @param string $on     The moment, in anything DateTimeImmutable reads.
	 *
	 * @return array<string, mixed>|null The holding.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function holderOn(string $caseId, string $on): ?array {
		$moment = $this->instant(value: $on);
		if ($moment === null) {
			return null;
		}

		$found = null;
		foreach ($this->chain->holdings(caseId: $caseId) as $holding) {
			$from = $this->instant(value: (string)($holding['from'] ?? ''));
			if ($from === null || $from > $moment) {
				continue;
			}

			$until = $this->instant(value: (string)($holding['until'] ?? ''));
			if ($until !== null && $until <= $moment) {
				continue;
			}

			$found = $holding;
		}

		return $found;
	}//end holderOn()

	/**
	 * The holdings a unit had that overlap a period.
	 *
	 * A holding counts when it started before the window ended and had not
	 * ended before the window began. An open holding has no end, so it counts
	 * whenever it started in time.
	 *
	 * @param string $organisationUnit The unit.
	 * @param string $from             The start of the window.
	 * @param string $to               The end of the window.
	 *
	 * @return array<int, array<string, mixed>> The holdings, oldest first.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function heldBy(string $organisationUnit, string $from, string $to): array {
		$organisationUnit = trim($organisationUnit);
		$windowStart = $this->instant(value: $from);
		$windowEnd = $this->instant(value: $to);
		if ($organisationUnit === '' || $windowStart === null || $windowEnd === null) {
			return [];
		}

		try {
			[$objectService, $register] = $this->context();
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'case_custody_schema'),
				filters: ['organisationUnit' => $organisationUnit, '_limit' => self::PAGE_SIZE],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq custody: the holdings of a unit could not be read',
				['organisationUnit' => $organisationUnit, 'exception' => $e->getMessage()],
			);

			return [];
		}

		$overlapping = [];
		foreach ($rows as $row) {
			$holdingStart = $this->instant(value: (string)($row['from'] ?? ''));
			if ($holdingStart === null || $holdingStart > $windowEnd) {
				continue;
			}

			$holdingEnd = $this->instant(value: (string)($row['until'] ?? ''));
			if ($holdingEnd !== null && $holdingEnd < $windowStart) {
				continue;
			}

			$overlapping[] = $row;
		}

		usort(
			$overlapping,
			static function (array $left, array $right): int {
				return (((string)($left['from'] ?? '')) <=> ((string)($right['from'] ?? '')));
			},
		);

		return $overlapping;
	}//end heldBy()

	/**
	 * A moment, or null when the value is empty or unreadable.
	 *
	 * @param string $value The candidate.
	 *
	 * @return DateTimeImmutable|null The moment.
	 */
	private function instant(string $value): ?DateTimeImmutable {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end instant()

	/**
	 * The object service and the register, or an exception.
	 *
	 * @return array{0: object, 1: string} The service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is absent or unconfigured.
	 */
	private function context(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		return [$objectService, $register];
	}//end context()

	/**
	 * A configured schema, or an exception naming the key.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema id or slug.
	 *
	 * @throws RuntimeException When the key is unset.
	 */
	private function schema(string $key): string {
		$schema = $this->settingsService->getConfigValue($key);
		if ($schema === '') {
			throw new RuntimeException('Dossiq schema ' . $key . ' not configured');
		}

		return $schema;
	}//end schema()
}//end class
