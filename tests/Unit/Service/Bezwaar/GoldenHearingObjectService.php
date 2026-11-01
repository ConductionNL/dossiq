<?php

/**
 * Object service double for the HearingService golden test
 *
 * Records every call by parameter name and answers from a behaviour table.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCP\AppFramework\Db\DoesNotExistException;
use RuntimeException;

/**
 * Object service double that records calls and answers from a behaviour table.
 */
class GoldenHearingObjectService {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $behaviour How the store answers.
	 * @param array<int, string>   $log       Shared log the calls are appended to.
	 */
	public function __construct(
		private readonly array $behaviour,
		private array &$log,
	) {
	}//end __construct()

	/**
	 * Find an object.
	 *
	 * @param string     $id       The id.
	 * @param int|string $register The register.
	 * @param int|string $schema   The schema.
	 *
	 * @return mixed
	 */
	public function find(string $id, int|string $register, int|string $schema): mixed {
		$this->note(call: ['find' => compact('id', 'register', 'schema')]);
		$answer = ($this->behaviour['find'][$id] ?? 'missing');
		if ($answer === 'missing') {
			throw new DoesNotExistException('not found');
		}

		if ($answer === 'throw') {
			throw new RuntimeException('find failed');
		}

		return $answer;
	}//end find()

	/**
	 * Save an object.
	 *
	 * @param array<string, mixed> $object   The object.
	 * @param int|string           $register The register.
	 * @param int|string           $schema   The schema.
	 * @param string|null          $uuid     The uuid.
	 *
	 * @return mixed
	 */
	public function saveObject(array $object, int|string $register, int|string $schema, ?string $uuid = null): mixed {
		$this->note(call: ['saveObject' => compact('object', 'register', 'schema', 'uuid')]);
		$mode = ($this->behaviour['save'] ?? 'ok');
		if ($mode === 'throw') {
			throw new RuntimeException('save failed');
		}

		if ($mode === 'null') {
			return null;
		}

		return array_merge($object, ['@self' => ['id' => 'saved-1']]);
	}//end saveObject()

	/**
	 * Patch an object.
	 *
	 * @param string               $objectId The id.
	 * @param array<string, mixed> $data     The changes.
	 * @param int|string           $register The register.
	 * @param int|string           $schema   The schema.
	 *
	 * @return mixed
	 */
	public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): mixed {
		$this->note(call: ['patchObject' => compact('objectId', 'data', 'register', 'schema')]);
		$mode = ($this->behaviour['patch'] ?? 'ok');
		if ($mode === 'throw') {
			throw new RuntimeException('patch failed');
		}

		if ($mode === 'null') {
			return null;
		}

		return ['patched' => $objectId] + $data;
	}//end patchObject()

	/**
	 * Search by slug.
	 *
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param array<string, mixed> $filters  The filters.
	 * @param mixed                ...$scope Extra scope flags.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters, mixed ...$scope): array {
		$this->note(call: ['searchObjectsBySlug' => compact('register', 'schema', 'filters', 'scope')]);
		$mode = ($this->behaviour['search'] ?? 'none');
		if ($mode === 'throw') {
			throw new RuntimeException('search failed');
		}

		if ($mode === 'some') {
			return [['id' => 'existing-1']];
		}

		return [];
	}//end searchObjectsBySlug()

	/**
	 * Append one call to the shared log.
	 *
	 * @param array<string, mixed> $call The call.
	 *
	 * @return void
	 */
	private function note(array $call): void {
		$this->log[] = json_encode(['objects' => $call], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}//end note()
}//end class
