<?php

/**
 * Dossiq file restriction mirror: a document's exception, applied to its file in OpenRegister
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use Psr\Container\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Restricts and frees a document's file through OpenRegister's FileRestrictionService (per-file-read-restriction).
 *
 * The file sits in the case's folder, which every reader of the case can list through OpenRegister. Without this the
 * exception would hide the document's row and still serve its bytes.
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
 */
class FileRestrictionMirror {

	/**
	 * OpenRegister's service, resolved at call time: OpenRegister is an optional dependency.
	 */
	public const SERVICE = 'OCA\OpenRegister\Service\File\FileRestrictionService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's service.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * Whether OpenRegister on this instance can restrict a file.
	 *
	 * @return bool True when the service resolves.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
	 */
	public function isAvailable(): bool {
		return $this->service() !== null;
	}//end isAvailable()

	/**
	 * Restrict a file to the users and groups of an exception.
	 *
	 * @param int          $fileId The Nextcloud file id.
	 * @param list<string> $users  The users.
	 * @param list<string> $groups The groups.
	 * @param string       $reason Why.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister cannot restrict files.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
	 */
	public function restrict(int $fileId, array $users, array $groups, string $reason): void {
		$this->requireService()->restrict($fileId, $users, $groups, $reason);
	}//end restrict()

	/**
	 * Free a file of its restriction.
	 *
	 * @param int $fileId The Nextcloud file id.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When OpenRegister cannot restrict files.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
	 */
	public function lift(int $fileId): void {
		$this->requireService()->lift($fileId);
	}//end lift()

	/**
	 * The service, or a refusal.
	 *
	 * @return object The service.
	 *
	 * @throws RuntimeException When it does not resolve.
	 */
	private function requireService(): object {
		$service = $this->service();
		if ($service === null) {
			throw new RuntimeException('OpenRegister on this instance cannot restrict a file');
		}

		return $service;
	}//end requireService()

	/**
	 * OpenRegister's service, or null.
	 *
	 * @return object|null The service.
	 */
	private function service(): ?object {
		if (class_exists(self::SERVICE) === false) {
			return null;
		}

		$service = null;
		try {
			$service = $this->container->get(self::SERVICE);
		} catch (Throwable $e) {
			$service = null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end service()
}//end class
