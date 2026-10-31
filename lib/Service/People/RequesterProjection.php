<?php

/**
 * Dossiq requester projection.
 *
 * A case names its requester by one uuid, `requester`, pointing at a
 * `brpPerson` or a `kvkCompany` row. The case list, its filter and the case
 * page read three projected fields instead: `initiatorType`,
 * `initiatorSourceId` (the BSN or the KvK number) and `initiatorDisplayName`.
 * This class makes those three from the row the uuid names, in the shape the
 * picker writes them (src/services/initiatorSearch.js personResult and
 * companyResult), so a projection made on save reads the same as one a picker
 * made.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\People
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
 * @spec openspec/specs/initiator-display/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use OCA\Dossiq\Service\Support\TrustedRevealRead;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Throwable;

/**
 * The three projected requester fields of a requester uuid.
 *
 * @spec openspec/specs/initiator-display/spec.md
 */
class RequesterProjection {

	use SearchesObjects;

	/**
	 * The register sets a requester row lives in, with the type it projects as.
	 *
	 * @var array<string, string>
	 */
	public const SETS = [
		'brpPerson' => 'person',
		'kvkCompany' => 'company',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The register and the object service.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The projection of a requester uuid, or [] when no register set holds it.
	 *
	 * @param string $uuid The requester uuid.
	 *
	 * @return array{initiatorType?: string, initiatorSourceId?: string, initiatorDisplayName?: string}
	 *
	 * @spec openspec/specs/initiator-display/spec.md
	 */
	public function forRequester(string $uuid): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if (trim($uuid) === '' || $objectService === null || $register === '') {
			return [];
		}

		foreach (self::SETS as $schema => $type) {
			try {
				// The projection stores the requester's BSN, which OpenRegister shows only on request.
				$rows = (new TrustedRevealRead())->run(
					fn () => $this->searchObjectsAsArrays(
						objectService: $objectService,
						register: $register,
						schema: $schema,
						filters: ['_ids' => [$uuid], '_limit' => 1],
					)
				);
			} catch (Throwable) {
				continue;
			}

			if (is_array($rows[0] ?? null) === true) {
				return $this->shape(type: $type, row: $rows[0]);
			}
		}

		return [];
	}//end forRequester()

	/**
	 * The projection of one register row.
	 *
	 * @param string               $type `person` or `company`.
	 * @param array<string, mixed> $row  The row.
	 *
	 * @return array{initiatorType: string, initiatorSourceId: string, initiatorDisplayName: string}
	 */
	private function shape(string $type, array $row): array {
		if ($type === 'company') {
			return [
				'initiatorType' => 'company',
				'initiatorSourceId' => (string)($row['kvkNumber'] ?? ''),
				'initiatorDisplayName' => (string)($row['tradeName'] ?? ''),
			];
		}

		$name = '';
		if (is_array($row['name'] ?? null) === true) {
			$parts = [$row['name']['givenNames'] ?? '', $row['name']['namePrefix'] ?? '', $row['name']['surname'] ?? ''];
			$name = implode(' ', array_filter(array_map(static fn (mixed $part): string => trim((string)$part), $parts)));
		}

		$display = trim((string)($row['displayName'] ?? ''));
		if ($display === '') {
			$display = $name;
		}

		return [
			'initiatorType' => 'person',
			'initiatorSourceId' => (string)($row['citizenServiceNumber'] ?? ''),
			'initiatorDisplayName' => $display,
		];
	}//end shape()
}//end class
