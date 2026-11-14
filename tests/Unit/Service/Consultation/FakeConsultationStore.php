<?php

/**
 * A stand-in for OpenRegister's ObjectService over advice requests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Consultation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Consultation;

/**
 * Answers searches from a row list and records every call.
 */
class FakeConsultationStore {

	/**
	 * @var list<array>
	 */
	public array $calls = [];

	/**
	 * @param list<array>          $rows    The stored advice requests.
	 * @param array<string, mixed> $byId    find() answers by id.
	 */
	public function __construct(
		public array $rows = [],
		public array $byId = [],
	) {
	}

	public function searchObjects(array $query, mixed ...$scope): array {
		$this->calls[] = ['searchObjects', $query];

		return array_values(
			array_filter(
				$this->rows,
				static function (array $row) use ($query): bool {
					foreach (['status', 'parentCase'] as $field) {
						if (isset($query[$field]) === true && ($row[$field] ?? null) !== $query[$field]) {
							return false;
						}
					}

					if (isset($query['consultationNumber']) === true) {
						return str_starts_with((string)($row['consultationNumber'] ?? ''), rtrim($query['consultationNumber'], '%'));
					}

					return true;
				}
			)
		);
	}

	public function find(string $id, string $register, string $schema): mixed {
		$this->calls[] = ['find', $id, $register, $schema];
		if (array_key_exists($id, $this->byId) === false) {
			throw new \OCP\AppFramework\Db\DoesNotExistException('gone');
		}

		return $this->byId[$id];
	}

	public function deleteObject(string $uuid, string $register, string $schema): void {
		$this->calls[] = ['deleteObject', $uuid, $register, $schema];
	}
}
