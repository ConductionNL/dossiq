<?php

/**
 * Dossiq CaseNumberReader.
 *
 * Reads the number a case is known by (its `identifier`, e.g. 2026-0123) from
 * the case's UUID, for a letter that has to name the case to its reader.
 *
 * A term instance links its case by UUID, and several callers hand that UUID
 * to the letters as the case reference, so a requester read "zaak
 * cecc92cc-..." where the case number belonged (Zuiddrecht live round 3).
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-requester-letter-names-the-case-by-its-number
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers the case number for a case UUID.
 *
 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-requester-letter-names-the-case-by-its-number
 */
class CaseNumberReader {

	use SearchesObjects;

	/**
	 * A UUID, the shape of a case's id and never of its number.
	 */
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Where the register, the case schema and the object service come from.
	 * @param LoggerInterface $logger   Logger.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether a case reference is a UUID rather than a number a person reads.
	 *
	 * @param string $reference The reference a letter was handed.
	 *
	 * @return bool True for a UUID.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-requester-letter-names-the-case-by-its-number
	 */
	public function isCaseId(string $reference): bool {
		return preg_match(self::UUID, trim($reference)) === 1;
	}//end isCaseId()

	/**
	 * The number of the case with this UUID, or '' when it has none or cannot be read.
	 *
	 * Read as the system: a letter goes out from a background job as often as
	 * from a request, and the case number is not a secret from its own letter.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return string The case number, or ''.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-requester-letter-names-the-case-by-its-number
	 */
	public function numberOf(string $caseId): string {
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue('register');
		$schema = $this->settings->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '' || trim($caseId) === '') {
			return '';
		}

		try {
			$row = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): ?array => $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: trim($caseId))
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the case number for a letter could not be read', ['case' => $caseId, 'error' => $e->getMessage()]);
			return '';
		}

		if (is_array($row) === false) {
			return '';
		}

		return trim((string)($row['identifier'] ?? ''));
	}//end numberOf()
}//end class
