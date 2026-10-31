<?php

/**
 * Dossiq case type delete guard.
 *
 * A case type is deleted only while it is a draft that no case uses, and
 * its status types go with it. The guard subscribes to OpenRegister's
 * pre-persist, stoppable `ObjectDeletingEvent`, so it holds for every path
 * that deletes a case type: the generic object API behind the case types
 * list as much as any dossiq endpoint. It returns for every other schema.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-types/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses the delete of a published or used case type, and removes the
 * status types of one that may go.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseTypeDeleteGuard implements IEventListener {

	use SearchesObjects;

	/**
	 * The schema slug of a case type, matched when no config names it.
	 *
	 * @var string
	 */
	public const GUARDED_SCHEMA_SLUG = 'caseType';

	/**
	 * How many status types one case type is read with.
	 *
	 * @var int
	 */
	private const PAGE_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Config values and OpenRegister's ObjectService.
	 * @param IL10N           $l10n            Words the refusal.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse or allow a case type delete, and cascade an allowed one.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectDeletingEvent === false) {
			return;
		}

		$entity = $event->getObject();
		$payload = (array)($entity->getObject() ?? []);
		if ($this->isCaseType(schema: (string)$entity->getSchema()) === false) {
			return;
		}

		$uuid = (string)$entity->getUuid();
		$refusal = $this->refusal(uuid: $uuid, isDraft: ($payload['isDraft'] ?? false) === true);
		if ($refusal !== null) {
			$event->setErrors($refusal);
			$event->stopPropagation();
			$this->logger->info('Dossiq: refused the delete of a case type', ['caseType' => $uuid, 'error' => $refusal['error']]);
			return;
		}

		$this->removeStatusTypes(caseTypeId: $uuid);
	}//end handle()

	/**
	 * Why this case type may not be deleted, or null when it may.
	 *
	 * @param string $uuid    The case type.
	 * @param bool   $isDraft Whether it is still a draft.
	 *
	 * @return array{error: string, message: string}|null The refusal.
	 */
	private function refusal(string $uuid, bool $isDraft): ?array {
		if ($isDraft === false) {
			return [
				'error'   => 'case-type-published',
				'message' => $this->l10n->t('A published case type cannot be deleted. Deprecate it, or make a new version.'),
			];
		}

		$cases = $this->countCases(caseTypeId: $uuid);
		if ($cases > 0) {
			return [
				'error'   => 'case-type-in-use',
				'message' => $this->l10n->t('Cases use this case type, so it cannot be deleted.'),
			];
		}

		if ($cases < 0) {
			return [
				'error'   => 'case-type-unchecked',
				'message' => $this->l10n->t('This case type cannot be deleted now, because the cases that use it could not be checked. Try again later.'),
			];
		}

		return null;
	}//end refusal()

	/**
	 * How many cases use a case type: 0 or 1 is enough to decide, and -1
	 * when it could not be read.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return int The count, capped at one, or -1.
	 */
	private function countCases(string $caseTypeId): int {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return -1;
		}

		try {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['caseType' => $caseTypeId, '_limit' => 1],
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: could not read the cases of a case type', ['caseType' => $caseTypeId, 'error' => $e->getMessage()]);
			return -1;
		}

		return min(1, count($rows));
	}//end countCases()

	/**
	 * Delete the status types of a case type that is being deleted.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return void
	 */
	private function removeStatusTypes(string $caseTypeId): void {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('status_type_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return;
		}

		try {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['caseType' => $caseTypeId, '_limit' => self::PAGE_SIZE],
			);
			foreach ($rows as $row) {
				$id = (string)($row['id'] ?? ($row['@self']['id'] ?? ''));
				if ($id !== '') {
					$objectService->deleteObject(uuid: $id, register: $register, schema: $schema);
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not remove the status types of a deleted case type',
				['caseType' => $caseTypeId, 'error' => $e->getMessage()]
			);
		}
	}//end removeStatusTypes()

	/**
	 * Whether a schema reference names the case type schema.
	 *
	 * @param string $schema The entity's schema id or slug.
	 *
	 * @return bool True for the case type schema.
	 */
	private function isCaseType(string $schema): bool {
		if ($schema === '') {
			return false;
		}

		foreach ([self::GUARDED_SCHEMA_SLUG, $this->settingsService->getConfigValue('case_type_schema')] as $name) {
			if ($name !== '' && ($schema === $name || str_ends_with($schema, '/' . $name) === true)) {
				return true;
			}
		}

		return false;
	}//end isCaseType()
}//end class
