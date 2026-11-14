<?php

/**
 * Dossiq Woo dossier return
 *
 * BRINGS A PUBLISHED DECISION BACK TO THE DOSSIER THE REQUEST CAME FROM (hydra
 * woo-citizen-journey C6, journey step J5.5). A Woo request started from a
 * resident's dossier carries that dossier in `case.wooRequest.collectionId`.
 * When the decision is published, the new publication is appended to the
 * dossier as one item with `addedBy: dossiq`, the item shape of C1. Another
 * app may append; it never changes or removes an item, so this class only
 * ever adds one, and only when the dossier does not hold that publication yet.
 *
 * The publication is the decision; the dossier item is a courtesy. So a
 * dossier that cannot be read or written is logged and answered with false,
 * and never undoes a publish.
 *
 * The decision also comes back to the resident who asked: on the first
 * publish {@see self::tellTheResident()} has WooDecisionNotice write them a
 * message that says the decision is published and links to it.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\UnscopedObjectReader;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Appends a published Woo decision to its source dossier, once.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
 */
class WooDossierReturn {

	use SearchesObjects;

	/**
	 * Who added the item, in C1's `addedBy` vocabulary.
	 */
	public const ADDED_BY = 'dossiq';

	/**
	 * Reads the resident's collection as the system, unscoped.
	 *
	 * @var UnscopedObjectReader
	 */
	private readonly UnscopedObjectReader $reader;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface           $settingsService Where OpenRegister and the collection schema are.
	 * @param ISecureRandom             $random          Makes the item id.
	 * @param LoggerInterface           $logger          Logger.
	 * @param WooDecisionNotice|null    $decisionNotice  Tells the resident the decision is published.
	 * @param UnscopedObjectReader|null $reader          Reads unscoped; a fresh one when null.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly ISecureRandom $random,
		private readonly LoggerInterface $logger,
		private readonly ?WooDecisionNotice $decisionNotice = null,
		?UnscopedObjectReader $reader = null,
	) {
		$this->reader = ($reader ?? new UnscopedObjectReader());
	}//end __construct()

	/**
	 * Tell the resident the decision on their request is published, once.
	 *
	 * Only on the first publish: a republish keeps the link the resident
	 * already has, and a withdrawal leaves it on the case, so a case that
	 * already carries `wooPublicationUrl` tells nobody again.
	 *
	 * @param array<string, mixed> $case          The case, as it was before this publish.
	 * @param string               $caseId        The case uuid.
	 * @param string               $publicationId The publication uuid.
	 *
	 * @return bool Whether the resident was told.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
	 */
	public function tellTheResident(array $case, string $caseId, string $publicationId): bool {
		if ($this->decisionNotice === null || (string)($case['wooPublicationUrl'] ?? '') !== '') {
			return false;
		}

		return $this->decisionNotice->tell(case: $case, caseId: $caseId, publicationId: $publicationId);
	}//end tellTheResident()

	/**
	 * Append the publication to the case's source dossier.
	 *
	 * The dossier is the resident's, in another app's register and tenant:
	 * read under the handler's own scope it is not there, and the decision
	 * never came back to it (rehearsal R3, "the source dossier is gone"). So
	 * it is read and written unscoped, and only this one collection: the one
	 * the case names, and only while its owner is still the case's requester,
	 * the check the intake made (REQ-WRI-003).
	 *
	 * @param array<string, mixed> $case          The case, with `wooRequest` and `portalSubject`.
	 * @param string               $publicationId The publication uuid.
	 * @param string               $title         What the item's note says.
	 *
	 * @return bool True when the dossier holds the publication afterwards.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
	 */
	public function append(array $case, string $publicationId, string $title): bool {
		$collectionId = trim((string)($case['wooRequest']['collectionId'] ?? ''));
		$objectService = $this->settingsService->getObjectService();
		if ($this->canAppend(collectionId: $collectionId, publicationId: $publicationId, objectService: $objectService) === false) {
			return false;
		}

		$target = $this->appendTarget(objectService: $objectService, collectionId: $collectionId);

		try {
			return $this->appendToCollection(target: $target, case: $case, publicationId: $publicationId, title: $title);
		} catch (Throwable $e) {
			$this->warnNotAppended(collectionId: $collectionId, publicationId: $publicationId, error: $e);
			return false;
		}
	}//end append()

	/**
	 * Log that the decision could not be added to its source dossier.
	 *
	 * @param string    $collectionId  The source dossier uuid.
	 * @param string    $publicationId The publication uuid.
	 * @param Throwable $error         What went wrong.
	 *
	 * @return void
	 */
	private function warnNotAppended(string $collectionId, string $publicationId, Throwable $error): void {
		$this->logger->warning(
			'WooDossierReturn: the decision could not be added to its source dossier',
			['collection' => $collectionId, 'publication' => $publicationId, 'error' => $error->getMessage()]
		);
	}//end warnNotAppended()

	/**
	 * The object service, register, schema and id of the source dossier.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $collectionId  The source dossier uuid.
	 *
	 * @return array{objectService: object, register: string, schema: string, id: string}
	 */
	private function appendTarget(object $objectService, string $collectionId): array {
		$register = $this->settingsService->getWooPublicationConfigValue('woo_collection_register');
		$schema = $this->settingsService->getWooPublicationConfigValue('woo_collection_schema');
		return ['objectService' => $objectService, 'register' => $register, 'schema' => $schema, 'id' => $collectionId];
	}//end appendTarget()

	/**
	 * Add the publication to the source dossier when it is still the requester's.
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string} $target        The object service, register,
	 *                                                                                              schema and collection id.
	 * @param array<string, mixed>                                                       $case          The case, with `portalSubject`.
	 * @param string                                                                     $publicationId The publication uuid.
	 * @param string                                                                     $title         What the item's note says.
	 *
	 * @return bool True when the dossier holds the publication afterwards.
	 *
	 * @throws Throwable When reading or writing the dossier fails.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
	 */
	private function appendToCollection(array $target, array $case, string $publicationId, string $title): bool {
		$collection = $this->ownedCollection(target: $target, case: $case);
		if ($collection === null) {
			return false;
		}

		$items = $this->itemsWith(collection: $collection, publicationId: $publicationId, title: $title);
		if ($items === null) {
			return true;
		}

		$this->runAsSystemIfAvailable(
			objectService: $target['objectService'],
			operation: fn (): mixed => $this->writeItems(target: $target, collection: $collection, items: $items)
		);

		return true;
	}//end appendToCollection()

	/**
	 * Whether a dossier, a publication and OpenRegister are all in hand.
	 *
	 * @param string      $collectionId  The source dossier uuid.
	 * @param string      $publicationId The publication uuid.
	 * @param object|null $objectService OpenRegister's object service.
	 *
	 * @return bool
	 */
	private function canAppend(string $collectionId, string $publicationId, ?object $objectService): bool {
		return !($collectionId === '' || $publicationId === '' || $objectService === null);
	}//end canAppend()

	/**
	 * Read the source dossier, unscoped, when it is still the requester's.
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string} $target The object service, register,
	 *                                                                                       schema and collection id.
	 * @param array<string, mixed>                                                       $case   The case, with `portalSubject`.
	 *
	 * @return array<string, mixed>|null The collection, or null when it is gone or not the requester's.
	 */
	private function ownedCollection(array $target, array $case): ?array {
		$collectionId = $target['id'];
		$collection = $this->reader->find(
			objectService: $target['objectService'],
			register: $target['register'],
			schema: $target['schema'],
			id: $collectionId
		);
		if (is_array($collection) === false) {
			$this->logger->warning('WooDossierReturn: the source dossier is gone', ['collection' => $collectionId]);
			return null;
		}

		if ($this->isTheRequesters(case: $case, collection: $collection) === false) {
			$this->logger->warning('WooDossierReturn: the source dossier is not the requester\'s', ['collection' => $collectionId]);
			return null;
		}

		return $collection;
	}//end ownedCollection()

	/**
	 * The collection's items with the publication added, or null when it already holds it.
	 *
	 * @param array<string, mixed> $collection    The collection as it was read.
	 * @param string               $publicationId The publication uuid.
	 * @param string               $title         What the item's note says.
	 *
	 * @return array<int, array<string, mixed>>|null The items to write, or null when nothing changes.
	 */
	private function itemsWith(array $collection, string $publicationId, string $title): ?array {
		$items = array_values(array_filter((array)($collection['items'] ?? []), 'is_array'));
		foreach ($items as $item) {
			if ((string)($item['publication'] ?? '') === $publicationId) {
				return null;
			}
		}

		// The shape of opencatalogi's `collection.items[]`: `id` is a uuid
		// and every property is a string, so a whole-publication item
		// leaves `attachment` out instead of writing null (OpenRegister
		// refuses the null, and the decision never reached the dossier).
		$items[] = [
			'id' => $this->uuid(),
			'publication' => $publicationId,
			'note' => $title,
			'addedAt' => date('c'),
			'addedBy' => self::ADDED_BY,
		];

		return $items;
	}//end itemsWith()

	/**
	 * Whether the collection is still owned by the case's requester.
	 *
	 * @param array<string, mixed> $case       The case, with `portalSubject`.
	 * @param array<string, mixed> $collection The collection as it was read.
	 *
	 * @return bool
	 */
	private function isTheRequesters(array $case, array $collection): bool {
		$requester = trim((string)($case['portalSubject'] ?? ''));

		return $requester !== '' && (string)($collection['owner'] ?? '') === $requester;
	}//end isTheRequesters()

	/**
	 * Write the collection's items, unscoped, and nothing else of it.
	 *
	 * Through the PATCH seam when OpenRegister offers it, so a field another
	 * app wrote meanwhile (a share, a note) is not saved away; otherwise the
	 * collection as it was just read, with the new items.
	 *
	 * @param array{objectService: object, register: string, schema: string, id: string} $target     The object service and the
	 *                                                                                           collection's register, schema and uuid.
	 * @param array<string, mixed>                                                       $collection The collection as it was read.
	 * @param array<int, array<string, mixed>>                                           $items      Its items, with the publication.
	 *
	 * @return mixed What OpenRegister answered.
	 */
	private function writeItems(array $target, array $collection, array $items): mixed {
		$objectService = $target['objectService'];
		if (method_exists($objectService, 'patchObject') === true) {
			return $objectService->patchObject(
				objectId: $target['id'],
				data: ['items' => $items],
				register: $target['register'],
				schema: $target['schema'],
				_rbac: false,
				_multitenancy: false,
			);
		}

		$data = $collection;
		unset($data['@self'], $data['id']);
		$data['items'] = $items;

		return $objectService->saveObject(
			object: $data,
			register: $target['register'],
			schema: $target['schema'],
			uuid: $target['id'],
			_rbac: false,
			_multitenancy: false,
		);
	}//end writeItems()

	/**
	 * A random version 4 uuid, the format the collection schema gives `items[].id`.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
	 */
	private function uuid(): string {
		$hex = $this->random->generate(32, '0123456789abcdef');
		$hex[12] = '4';
		$hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
	}//end uuid()
}//end class
