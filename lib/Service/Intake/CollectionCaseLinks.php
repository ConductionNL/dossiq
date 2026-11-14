<?php

/**
 * Dossiq collection case links.
 *
 * A resident can start a case from a collection they keep of published
 * documents. This class reads that collection only when it is the resident's
 * own, writes one case object per item that names a publication, and records
 * the case on the collection's `sourceOf`, once. The collection's register and
 * schema, and the publication's, are configuration.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
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
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Support\UnscopedObjectReader;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Links a resident's own collection of publications to the case it started.
 *
 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
 */
class CollectionCaseLinks {

	use SearchesObjects;

	/**
	 * The case object type of a publication from a collection.
	 *
	 * @var string
	 */
	public const OBJECT_TYPE = 'opencatalogi.publication';

	/**
	 * The prefix of the reference a collection keeps in `sourceOf`.
	 *
	 * @var string
	 */
	public const SOURCE_PREFIX = 'dossiq:case:';

	/**
	 * Reads the collection and the publications as the system, unscoped.
	 *
	 * @var UnscopedObjectReader
	 */
	private readonly UnscopedObjectReader $reader;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface           $settingsService Schemas, and the collection and publication configuration.
	 * @param IURLGenerator             $urlGenerator    Makes the publication links absolute.
	 * @param LoggerInterface           $logger          Logger.
	 * @param UnscopedObjectReader|null $reader          Reads unscoped; a fresh one when null.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
		?UnscopedObjectReader $reader = null,
	) {
		$this->reader = ($reader ?? new UnscopedObjectReader());
	}//end __construct()

	/**
	 * The resident's own collection, or null when it is absent or someone else's.
	 *
	 * Absent and not-yours answer the same, so the caller's refusal does not
	 * say whether the collection exists.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $collectionId  The collection uuid.
	 * @param string $subjectRef    The resident.
	 *
	 * @return array<string, mixed>|null The collection, with its `id`.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-only-the-owners-dossier-starts-a-request-req-wri-003
	 */
	public function owned(object $objectService, string $collectionId, string $subjectRef): ?array {
		$collection = null;
		try {
			$collection = $this->reader->find(
				objectService: $objectService,
				register: $this->settingsService->getWooPublicationConfigValue('woo_collection_register'),
				schema: $this->settingsService->getWooPublicationConfigValue('woo_collection_schema'),
				id: $collectionId,
			);
		} catch (Throwable $e) {
			$this->logger->warning('CollectionCaseLinks: the collection could not be read', ['collection' => $collectionId, 'error' => $e->getMessage()]);
		}

		if (is_array($collection) === false || (string)($collection['owner'] ?? '') !== $subjectRef) {
			return null;
		}

		$collection['id'] = $collectionId;

		return $collection;
	}//end owned()

	/**
	 * Link the collection to the case: one case object per item, then the
	 * case on the collection's `sourceOf`.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param string               $register      The dossiq register.
	 * @param string               $caseId        The case.
	 * @param array<string, mixed> $collection    The collection as owned() read it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
	 */
	public function link(object $objectService, string $register, string $caseId, array $collection): void {
		$link = new CollectionLink(objectService: $objectService, register: $register, caseId: $caseId, collection: $collection);
		$this->writeCaseObjects(link: $link);
		$this->recordSource(link: $link);
	}//end link()

	/**
	 * The public path of a publication, the same one the publish side links to.
	 *
	 * @param string $publicationId The publication uuid.
	 *
	 * @return string The path, starting with `/index.php`.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-one-service-creates-every-woo-request-req-wri-002
	 */
	public function publicationPath(string $publicationId): string {
		$catalogSlug = $this->settingsService->getConfigValue('woo_publication_catalog_slug', 'publication');

		return '/index.php/apps/opencatalogi/' . $catalogSlug . '/' . $publicationId;
	}//end publicationPath()

	/**
	 * One case object per collection item.
	 *
	 * @param CollectionLink $link The object service, the dossiq register, the case and the collection.
	 *
	 * @return void
	 */
	private function writeCaseObjects(CollectionLink $link): void {
		$objectService = $link->objectService;
		$register = $link->register;
		$schema = $this->settingsService->getConfigValue('case_object_schema');
		foreach ((array)($link->collection['items'] ?? []) as $item) {
			$publication = $this->publicationOf(item: $item);
			if ($publication === '') {
				continue;
			}

			$object = $this->caseObjectFor(link: $link, item: $item, publication: $publication);

			try {
				$this->runAsSystemIfAvailable(
					objectService: $objectService,
					operation: fn (): mixed => $objectService->saveObject(object: $object, register: $register, schema: $schema, _rbac: false, _multitenancy: false)
				);
			} catch (Throwable $e) {
				$this->logger->error(
					'CollectionCaseLinks: a collection item could not be linked to the case',
					['case' => $link->caseId, 'publication' => $publication, 'error' => $e->getMessage()]
				);
			}
		}//end foreach
	}//end writeCaseObjects()

	/**
	 * The publication a collection item names, or '' for an item that is not one.
	 *
	 * @param mixed $item The collection item.
	 *
	 * @return string The publication uuid, or ''.
	 */
	private function publicationOf(mixed $item): string {
		if (is_array($item) === false) {
			return '';
		}

		return trim((string)($item['publication'] ?? ''));
	}//end publicationOf()

	/**
	 * The case object that links one collection item to the case.
	 *
	 * @param CollectionLink       $link        The object service and the case.
	 * @param array<string, mixed> $item        The collection item.
	 * @param string               $publication The publication uuid it names.
	 *
	 * @return array<string, mixed> The case object.
	 */
	private function caseObjectFor(CollectionLink $link, array $item, string $publication): array {
		$attachment = ($item['attachment'] ?? null);
		if ($attachment !== null) {
			$attachment = (string)$attachment;
		}

		return [
			'case' => $link->caseId,
			'objectType' => self::OBJECT_TYPE,
			'objectUrl' => $this->urlGenerator->getAbsoluteURL($this->publicationPath(publicationId: $publication)),
			// The schema declares a string, so the pair travels as JSON.
			'objectIdentification' => (string)json_encode(['publication' => $publication, 'attachment' => $attachment]),
			'description' => $this->describe(objectService: $link->objectService, publication: $publication, note: (string)($item['note'] ?? '')),
		];
	}//end caseObjectFor()

	/**
	 * What a case object says: the publication's title and the resident's note.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $publication   The publication uuid.
	 * @param string $note          The resident's note on the item.
	 *
	 * @return string
	 */
	private function describe(object $objectService, string $publication, string $note): string {
		$title = '';
		try {
			$row = $this->reader->find(
				objectService: $objectService,
				register: $this->settingsService->getWooPublicationConfigValue('woo_publication_register'),
				schema: $this->settingsService->getWooPublicationConfigValue('woo_publication_schema'),
				id: $publication,
			);
			$title = trim((string)($row['title'] ?? ''));
		} catch (Throwable $e) {
			$title = '';
		}

		$note = trim($note);
		if ($title !== '' && $note !== '') {
			return $title . ': ' . $note;
		}

		if ($title !== '') {
			return $title;
		}

		return $note;
	}//end describe()

	/**
	 * Append the case to the collection's `sourceOf`, once, leaving its items alone.
	 *
	 * @param CollectionLink $link The object service, the dossiq register, the case and the collection.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
	 */
	private function recordSource(CollectionLink $link): void {
		$reference = self::SOURCE_PREFIX . $link->caseId;
		$sourceOf = array_values(array_filter((array)($link->collection['sourceOf'] ?? []), 'is_string'));
		if (in_array($reference, $sourceOf, true) === true) {
			return;
		}

		$data = $this->withSource(collection: $link->collection, sourceOf: array_merge($sourceOf, [$reference]));

		$this->saveSource(link: $link, data: $data);
	}//end recordSource()

	/**
	 * The collection as it is written back, carrying the new `sourceOf`.
	 *
	 * @param array<string, mixed> $collection The collection as read.
	 * @param array<int, string>   $sourceOf   The new `sourceOf`.
	 *
	 * @return array<string, mixed> The collection to save.
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
	 */
	private function withSource(array $collection, array $sourceOf): array {
		$data = $collection;
		unset($data['@self'], $data['id']);
		$data['sourceOf'] = $sourceOf;

		return $data;
	}//end withSource()

	/**
	 * Save the collection, logging when it cannot learn about the case.
	 *
	 * @param CollectionLink       $link The object service, the case and the collection, with its id.
	 * @param array<string, mixed> $data The collection to save.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
	 */
	private function saveSource(CollectionLink $link, array $data): void {
		$collectionId = (string)$link->collection['id'];
		try {
			$this->saveCollection(objectService: $link->objectService, data: $data, collectionId: $collectionId);
		} catch (Throwable $e) {
			// The case exists and is the resident's; a collection that does not
			// learn about it loses a link, not the request.
			$this->logger->warning(
				'CollectionCaseLinks: the collection could not record the case it started',
				['collection' => $collectionId, 'case' => $link->caseId, 'error' => $e->getMessage()]
			);
		}
	}//end saveSource()

	/**
	 * Save the collection under the system identity when one is available.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $data          The collection to save.
	 * @param string               $collectionId  The collection's id.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-request-intake/spec.md#requirement-the-dossier-records-the-request-it-started-req-wri-004
	 */
	private function saveCollection(object $objectService, array $data, string $collectionId): void {
		$this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): mixed => $objectService->saveObject(
				object: $data,
				register: $this->settingsService->getWooPublicationConfigValue('woo_collection_register'),
				schema: $this->settingsService->getWooPublicationConfigValue('woo_collection_schema'),
				uuid: $collectionId,
				_rbac: false,
				_multitenancy: false,
			)
		);
	}//end saveCollection()
}//end class
