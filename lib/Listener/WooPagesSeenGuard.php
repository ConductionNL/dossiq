<?php

/**
 * Dossiq Woo pages-seen guard: no verdict through the OpenRegister API before the required pages are seen.
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
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Woo\WooPagesSeen;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses a `wooDocumentAssessment` write that sets a verdict on an in-scope document with unseen required pages.
 *
 * `bulkAssess` checks the same rule before it writes; this listener covers
 * the writes that go to OpenRegister directly. A write that does not set or
 * change the verdict passes, so correcting a note on an assessment never
 * waits for pages.
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
 *
 * @template-implements IEventListener<Event>
 */
class WooPagesSeenGuard implements IEventListener {

	/**
	 * The error code OpenRegister answers with.
	 */
	public const ERROR_CODE = 'woo.pages-unseen';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings, for the assessment schema.
	 * @param WooPagesSeen $pagesSeen The required and seen pages of each document.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WooPagesSeen $pagesSeen,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Inspect an assessment create or update.
	 *
	 * @param Event $event The OpenRegister event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->inspect(event: $event, incoming: $event->getObject(), stored: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->inspect(event: $event, incoming: $event->getNewObject(), stored: $event->getOldObject());
		}
	}//end handle()

	/**
	 * Refuse the write when it sets a verdict on a document with unseen required pages.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The event.
	 * @param ObjectEntity $incoming The object being written.
	 * @param ObjectEntity|null $stored The stored object on an update.
	 *
	 * @return void
	 */
	private function inspect(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $incoming, ?ObjectEntity $stored): void {
		if ($this->isAssessment(object: $incoming) === false) {
			return;
		}

		$new = $this->fields(object: $incoming);
		$verdict = (string)($new['classification'] ?? '');
		if ($verdict === '') {
			return;
		}

		if ($stored !== null && (string)($this->fields(object: $stored)['classification'] ?? '') === $verdict) {
			return;
		}

		$documentRef = (string)($new['documentRef'] ?? '');
		$unseen = $this->pagesSeen->unseenFor(caseId: (string)($new['caseRef'] ?? ''), documentRef: $documentRef);
		if ($unseen === []) {
			return;
		}

		$event->setErrors(
			[
				'message' => $this->pagesSeen->sentence(pages: $unseen),
				'code' => self::ERROR_CODE,
				'documentRef' => $documentRef,
				'pages' => $unseen,
			]
		);
		$event->stopPropagation();
		$this->logger->info('Dossiq: refused a Woo verdict before every required page was seen', ['document' => $documentRef, 'pages' => $unseen]);
	}//end inspect()

	/**
	 * Whether the object is a `wooDocumentAssessment`.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return bool True for the assessment schema.
	 */
	private function isAssessment(ObjectEntity $object): bool {
		$expected = $this->settingsService->getConfigValue('woo_assessment_schema');
		$schema = (string)$object->getSchema();

		return $expected !== '' && $schema !== '' && ($schema === $expected || str_ends_with($schema, '/'.$expected));
	}//end isAssessment()

	/**
	 * The object's own fields, or none when they cannot be read.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function fields(ObjectEntity $object): array {
		try {
			return $object->getObject();
		} catch (Throwable $e) {
			$this->logger->debug('Dossiq: the Woo pages-seen guard could not read an object: '.$e->getMessage());
			return [];
		}
	}//end fields()
}//end class
