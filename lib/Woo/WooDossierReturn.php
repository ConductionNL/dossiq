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
 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Appends a published Woo decision to its source dossier, once.
 *
 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
 */
class WooDossierReturn {

	use SearchesObjects;

	/**
	 * Who added the item, in C1's `addedBy` vocabulary.
	 */
	public const ADDED_BY = 'dossiq';

	/**
	 * Constructor.
	 *
	 * @param SettingsService        $settingsService Where OpenRegister and the collection schema are.
	 * @param ISecureRandom          $random          Makes the item id.
	 * @param LoggerInterface        $logger          Logger.
	 * @param WooDecisionNotice|null $decisionNotice  Tells the resident the decision is published.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ISecureRandom $random,
		private readonly LoggerInterface $logger,
		private readonly ?WooDecisionNotice $decisionNotice = null,
	) {
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
	 * @spec openspec/changes/portal-pages-in-resident-groups/specs/portal-contribution/spec.md#requirement-the-decision-notice-says-what-happened
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
	 * @param array<string, mixed> $case          The case, with `wooRequest`.
	 * @param string               $publicationId The publication uuid.
	 * @param string               $title         What the item's note says.
	 *
	 * @return bool True when the dossier holds the publication afterwards.
	 *
	 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
	 */
	public function append(array $case, string $publicationId, string $title): bool {
		$collectionId = trim((string)($case['wooRequest']['collectionId'] ?? ''));
		$objectService = $this->settingsService->getObjectService();
		if ($collectionId === '' || $publicationId === '' || $objectService === null) {
			return false;
		}

		$register = $this->settingsService->getWooPublicationConfigValue('woo_collection_register');
		$schema = $this->settingsService->getWooPublicationConfigValue('woo_collection_schema');

		try {
			$collection = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): ?array => $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $collectionId)
			);
			if (is_array($collection) === false) {
				$this->logger->warning('WooDossierReturn: the source dossier is gone', ['collection' => $collectionId]);
				return false;
			}

			$items = array_values(array_filter((array)($collection['items'] ?? []), 'is_array'));
			foreach ($items as $item) {
				if ((string)($item['publication'] ?? '') === $publicationId) {
					return true;
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

			// Only `items` changes, through the PATCH seam, so a field another
			// app wrote meanwhile (a share, a note) is not saved away.
			$this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): ?array => $this->patchObjectAsArray(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					id: $collectionId,
					changes: ['items' => $items],
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'WooDossierReturn: the decision could not be added to its source dossier',
				['collection' => $collectionId, 'publication' => $publicationId, 'error' => $e->getMessage()]
			);
			return false;
		}//end try

		return true;
	}//end append()

	/**
	 * A random version 4 uuid, the format the collection schema gives `items[].id`.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/woo-publish-decision-from-the-case/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
	 */
	private function uuid(): string {
		$hex = $this->random->generate(32, '0123456789abcdef');
		$hex[12] = '4';
		$hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
	}//end uuid()
}//end class
