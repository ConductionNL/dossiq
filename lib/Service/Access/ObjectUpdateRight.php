<?php

/**
 * Asks OpenRegister whether the signed-in user may update one object.
 *
 * The answer is OpenRegister's own RBAC verdict for `update` on the object's
 * schema, with the object passed along so object-level and conditional rules
 * count. Dossiq adds no rule of its own.
 *
 * It answers `false` whenever OpenRegister cannot be asked: a write that
 * cannot be checked is not made.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Access
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
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Access;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The update right on one object, as OpenRegister decides it.
 *
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */
class ObjectUpdateRight {

	/**
	 * OpenRegister's RBAC evaluator, resolved by name.
	 *
	 * @var string
	 */
	private const PERMISSION_HANDLER = 'OCA\OpenRegister\Service\Object\PermissionHandler';

	/**
	 * OpenRegister's schema lookup, resolved by name.
	 *
	 * @var string
	 */
	private const SCHEMA_MAPPER = 'OCA\OpenRegister\Db\SchemaMapper';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Tells whether OpenRegister is installed.
	 * @param ContainerInterface $container  Resolves OpenRegister's services by name.
	 * @param LoggerInterface    $logger     Records why no answer could be had.
	 *
	 * @spec openspec/changes/inspection-conflict-card/tasks.md
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the signed-in user may update this object.
	 *
	 * @param object $object The object as OpenRegister returned it.
	 * @param string $schema The object's schema id or slug, used when the object names none.
	 *
	 * @return bool True only when OpenRegister grants the update.
	 *
	 * @spec openspec/changes/inspection-conflict-card/tasks.md
	 */
	public function allows(object $object, string $schema): bool {
		if ($this->appManager->isInstalled('openregister') === false) {
			return false;
		}

		try {
			$handler = $this->container->get(self::PERMISSION_HANDLER);
			$schemaEntity = $this->container->get(self::SCHEMA_MAPPER)->find($this->schemaOf(object: $object, fallback: $schema));
			$granted = $handler->hasPermission(
				schema: $schemaEntity,
				action: 'update',
				userId: null,
				objectOwner: $this->ownerOf(object: $object),
				_rbac: true,
				object: $object,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: OpenRegister could not say whether the user may update the object, so the write is refused',
				['exception' => $e->getMessage(), 'schema' => $schema],
			);
			return false;
		}

		return ($granted === true);
	}//end allows()

	/**
	 * The schema the object names, else the fallback.
	 *
	 * @param object $object   The object.
	 * @param string $fallback The schema the caller named.
	 *
	 * @return string The schema id or slug.
	 */
	private function schemaOf(object $object, string $fallback): string {
		$named = null;
		if (is_callable([$object, 'getSchema']) === true) {
			$named = $object->getSchema();
		}

		if (is_scalar($named) === true && (string)$named !== '') {
			return (string)$named;
		}

		return $fallback;
	}//end schemaOf()

	/**
	 * The object's owner, or null when it names none.
	 *
	 * @param object $object The object.
	 *
	 * @return string|null The owner's uid.
	 */
	private function ownerOf(object $object): ?string {
		$owner = null;
		if (is_callable([$object, 'getOwner']) === true) {
			$owner = $object->getOwner();
		}

		if (is_string($owner) === true && $owner !== '') {
			return $owner;
		}

		return null;
	}//end ownerOf()
}//end class
