<?php

/**
 * The register and schema case transfers are read from and written to.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseTransfer
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
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseTransfer;

use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;

/**
 * One OpenRegister object service bound to the transfer register and schema.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
final class TransferStore {
	/**
	 * Constructor.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param int    $register      The register transfers live in.
	 * @param int    $schema        The transfer schema.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly int $register,
		public readonly int $schema,
	) {
	}//end __construct()

	/**
	 * Whether both the register and the schema are set.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md
	 */
	public function isConfigured(): bool {
		return empty($this->register) === false && empty($this->schema) === false;
	}//end isConfigured()

	/**
	 * The fields of one transfer, whether OpenRegister answers an object or an array.
	 *
	 * @param string $transferId The transfer.
	 *
	 * @return array<string, mixed>|null The fields, or null when it is not there.
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md
	 */
	public function read(string $transferId): ?array {
		$transfer = $this->objectService->find($transferId, options: new ObjectFindOptions(register: $this->register, schema: $this->schema));
		if ($transfer === null) {
			return null;
		}

		if (is_object($transfer) === true) {
			return $transfer->jsonSerialize();
		}

		return (array)$transfer;
	}//end read()

	/**
	 * Write one transfer.
	 *
	 * @param array<string, mixed> $transfer The transfer fields.
	 *
	 * @return mixed The saved object.
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md
	 */
	public function save(array $transfer): mixed {
		return $this->objectService->saveObject(object: $transfer, register: $this->register, schema: $this->schema);
	}//end save()
}//end class
