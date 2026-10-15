<?php

/**
 * Dossiq object read grants
 *
 * The OpenRegister side of case-scoped reviewer access (case-reviewer-access,
 * REQ-CRA-002): makes the user read grants and the owning group of one stored
 * record equal what the caller computed, through OpenRegister's own
 * per-object sharing (`ObjectSharingService::listGrants()`, `grant()`,
 * `revoke()`) and ownership (`ObjectOwnershipService::setOwnerGroup()`).
 *
 * OpenRegister's services are resolved by name, so an OpenRegister without
 * object-level sharing answers `available() === false` and nothing is
 * written. Every OpenRegister call refuses a caller who is neither the
 * record's owner nor an administrator nor in its owning group; that refusal
 * is counted, never swallowed into a success.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

use OCA\Dossiq\Service\SettingsService;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reconciles one record's user read grants and owning group.
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
 */
class ObjectReadGrants {

	/**
	 * OpenRegister's per-object sharing service.
	 */
	private const SHARING = 'OCA\OpenRegister\Service\Rbac\ObjectSharingService';

	/**
	 * OpenRegister's ownership service.
	 */
	private const OWNERSHIP = 'OCA\OpenRegister\Service\Rbac\ObjectOwnershipService';

	/**
	 * OpenRegister's register mapper.
	 */
	private const REGISTER_MAPPER = 'OCA\OpenRegister\Db\RegisterMapper';

	/**
	 * OpenRegister's schema mapper.
	 */
	private const SCHEMA_MAPPER = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * Core's read permission bit: a grant here never carries update, delete or share.
	 */
	public const READ = 1;

	/**
	 * Core's update permission bit: granted only where a case type says a reader may update.
	 */
	public const UPDATE = 2;

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService The object service and the register.
	 * @param ContainerInterface $container       Resolves OpenRegister's services by name.
	 * @param LoggerInterface    $logger          The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this OpenRegister has per-object sharing and ownership.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
	 */
	public function available(): bool {
		return $this->service(name: self::SHARING) !== null
			&& $this->service(name: self::OWNERSHIP) !== null
			&& $this->settingsService->getObjectService() !== null;
	}//end available()

	/**
	 * Make one record's user grants equal the readers, and its owning group the group.
	 *
	 * The record's owner is never granted: the private scope already admits
	 * them. A user grant on the record that is not a reader is revoked, and so
	 * is one that carries other permissions than the reader is due: it is
	 * granted again with the right ones. Only core's read and update bits are
	 * ever granted, read always.
	 *
	 * @param string             $schema  The record's schema slug.
	 * @param string             $uuid    The record's uuid.
	 * @param array<string, int> $readers Core permission bits by user id, as CaseRecordReaders::toGrant() answers them.
	 * @param string             $group   The owning group.
	 *
	 * @return array{granted: int, revoked: int, refused: int}
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-the-people-on-the-case-are-granted-read-and-nobody-else-req-cra-002
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-case-type-says-per-record-who-on-the-case-may-update-it-req-cra-010
	 */
	public function reconcile(string $schema, string $uuid, array $readers, string $group): array {
		$answer = ['granted' => 0, 'revoked' => 0, 'refused' => 0];
		$sharing = $this->service(name: self::SHARING);
		$object = $this->find(schema: $schema, uuid: $uuid);
		if ($sharing === null || $object === null) {
			$answer['refused'] = 1;
			return $answer;
		}

		try {
			$this->ownGroup(schema: $schema, object: $object, group: $group);
			$wanted = $this->wanted(readers: $readers, owner: (string)$object->getOwner());
			$held = $this->revokeOthers(sharing: $sharing, object: $object, wanted: $wanted);
			$answer['revoked'] = $held['revoked'];
			foreach ($wanted as $user => $permissions) {
				if (in_array((string)$user, $held['kept'], true) === true) {
					continue;
				}

				$sharing->grant($object, 'user', (string)$user, $permissions);
				$answer['granted']++;
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq read grants: OpenRegister refused to reconcile a record',
				['schema' => $schema, 'uuid' => $uuid, 'exception' => $e->getMessage()]
			);
			$answer['refused'] = 1;
		}//end try

		return $answer;
	}//end reconcile()

	/**
	 * The grants the record must carry: read, or read and update, per user, never for its owner.
	 *
	 * @param array<string, int> $readers Permission bits by user id.
	 * @param string             $owner   The record's owner.
	 *
	 * @return array<string, int>
	 */
	private function wanted(array $readers, string $owner): array {
		$wanted = [];
		foreach ($readers as $user => $permissions) {
			$user = trim((string)$user);
			if ($user === '' || $user === $owner) {
				continue;
			}

			$wanted[$user] = (self::READ | ((int)$permissions & self::UPDATE));
		}

		return $wanted;
	}//end wanted()

	/**
	 * Revoke every own user grant that is not wanted as it stands; keep one per wanted user.
	 *
	 * @param object             $sharing The sharing service.
	 * @param object             $object  The record entity.
	 * @param array<string, int> $wanted  The permission bits each reader is due.
	 *
	 * @return array{kept: list<string>, revoked: int}
	 */
	private function revokeOthers(object $sharing, object $object, array $wanted): array {
		$kept = [];
		$revoked = 0;
		foreach ($sharing->listGrants($object) as $grant) {
			if ((string)($grant['type'] ?? '') !== 'user' || ($grant['inherited'] ?? false) === true) {
				continue;
			}

			$user = (string)($grant['sharedWith'] ?? '');
			$bits = ((int)($grant['permissions'] ?? self::READ) & (self::READ | self::UPDATE));
			if (($wanted[$user] ?? 0) === $bits && in_array($user, $kept, true) === false) {
				$kept[] = $user;
				continue;
			}

			$sharing->revoke($object, (string)($grant['id'] ?? ''));
			$revoked++;
		}

		return ['kept' => $kept, 'revoked' => $revoked];
	}//end revokeOthers()

	/**
	 * Set the owning group when the record does not already name it.
	 *
	 * @param string $schema The schema slug.
	 * @param object $object The record entity.
	 * @param string $group  The owning group; '' leaves it alone.
	 *
	 * @return void
	 */
	private function ownGroup(string $schema, object $object, string $group): void {
		$block = (array)($object->getAuthorization() ?? []);
		if ($group === '' || (string)($block['ownerGroup'] ?? '') === $group) {
			return;
		}

		$register = $this->container->get(self::REGISTER_MAPPER)->find($this->settingsService->getConfigValue('register'));
		$schemaEntity = $this->container->get(self::SCHEMA_MAPPER)->find($schema);
		$this->service(name: self::OWNERSHIP)?->setOwnerGroup($register, $schemaEntity, $object, $group);
	}//end ownGroup()

	/**
	 * The record as OpenRegister's own entity, or null.
	 *
	 * @param string $schema The schema slug.
	 * @param string $uuid   The uuid.
	 *
	 * @return object|null
	 */
	private function find(string $schema, string $uuid): ?object {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || $register === '' || $uuid === '') {
			return null;
		}

		try {
			// A rule read: the caller manages this record's grants and may not
			// read it (decision 234). Nothing read here is answered to the caller.
			$object = $objectService->find($uuid, register: $register, schema: $schema, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($object) === false || method_exists($object, 'getOwner') === false) {
			return null;
		}

		return $object;
	}//end find()

	/**
	 * One OpenRegister service by name, or null.
	 *
	 * @param string $name The class name.
	 *
	 * @return object|null
	 */
	private function service(string $name): ?object {
		try {
			$service = $this->container->get($name);
		} catch (Throwable $e) {
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end service()
}//end class
