<?php

/**
 * The register and schema case shares are read from and written to.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseShare
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
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseShare;

use OCA\OpenRegister\Service\Object\ObjectFindTarget;
use OCA\OpenRegister\Service\Object\ObjectSaveOptions;

/**
 * One OpenRegister object service bound to the configured share register and schema.
 *
 * The register and schema are kept as configured; they are cast to ids only
 * where OpenRegister is called.
 *
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md
 */
final class ShareStore {
	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The configured register.
	 * @param string $schema        The configured share schema.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly string $schema,
	) {
	}//end __construct()

	/**
	 * Whether both the register and the schema are set.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md
	 */
	public function isConfigured(): bool {
		return empty($this->register) === false && empty($this->schema) === false;
	}//end isConfigured()

	/**
	 * The fields of one share, whether OpenRegister answers an object or an array.
	 *
	 * @param string $shareId The share.
	 *
	 * @return mixed The fields, or null when it is not there.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md
	 */
	public function read(string $shareId): mixed {
		$share = $this->objectService->find($shareId, register: (int)$this->register, schema: (int)$this->schema);
		if ($share === null || is_array($share) === true) {
			return $share;
		}

		return $share->jsonSerialize();
	}//end read()

	/**
	 * Write one share.
	 *
	 * @param array<string, mixed> $share The share fields.
	 *
	 * @return mixed The saved object.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md
	 */
	public function save(array $share): mixed {
		return $this->objectService->saveObject(
			object: $share,
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: (int)$this->register, schema: (int)$this->schema)
			)
		);
	}//end save()
}//end class
