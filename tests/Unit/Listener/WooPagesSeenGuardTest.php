<?php

/**
 * Unit tests for the Woo pages-seen guard.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\AppInfo\Registrar\WooListenerRegistrar;
use OCA\Dossiq\Listener\WooPagesSeenGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooDocumentReviews;
use OCA\Dossiq\Woo\WooPagesSeen;
use OCA\Dossiq\Woo\WooReviewDepth;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Direct OpenRegister writes of a verdict, through the OpenRegister event classes.
 *
 * @covers \OCA\Dossiq\Listener\WooPagesSeenGuard
 * @covers \OCA\Dossiq\AppInfo\Registrar\WooListenerRegistrar
 * @covers \OCA\Dossiq\Woo\WooPagesSeen
 *
 * @spec openspec/changes/woo-review-triage/specs/woo-review-triage/spec.md#requirement-nothing-is-decided-or-published-before-the-required-pages-are-seen-req-wrt-005
 */
class WooPagesSeenGuardTest extends TestCase {

	/**
	 * The store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * An in-scope 3-page document with no pages seen, and an out-of-scope one.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'wooDocumentReview', uuid: 'review-1', row: ['case' => 'case-x', 'documentRef' => 'doc-1', 'relevance' => 'in-scope', 'pagesRequired' => [1, 2, 3], 'pagesSeen' => []]);
		$this->store->seed(schema: 'wooDocumentReview', uuid: 'review-2', row: ['case' => 'case-x', 'documentRef' => 'doc-2', 'relevance' => 'out-of-scope', 'pagesRequired' => [1]]);
	}//end setUp()

	/**
	 * The guard on the store.
	 *
	 * @return WooPagesSeenGuard The guard.
	 */
	private function guard(): WooPagesSeenGuard {
		$config = ['register' => 'dossiq', 'woo_assessment_schema' => '42', 'woo_review_schema' => 'wooDocumentReview'];
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => ($config[$key] ?? $default));
		$logger = $this->createMock(LoggerInterface::class);

		return new WooPagesSeenGuard(
			settingsService: $settings,
			pagesSeen: new WooPagesSeen(reviews: new WooDocumentReviews(settingsService: $settings, logger: $logger), depth: new WooReviewDepth()),
			logger: $logger,
		);
	}//end guard()

	/**
	 * An object entity of a schema.
	 *
	 * @param array<string, mixed> $fields The fields.
	 * @param string $schema The schema id.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $fields, string $schema = '42'): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setObject($fields);
		$entity->setSchema($schema);
		$entity->setUuid('a-1');

		return $entity;
	}//end entity()

	/**
	 * The scenario: a PATCH to `openbaar` on an in-scope 3-page document with no pages seen is refused, naming pages 1 to 3.
	 *
	 * @return void
	 */
	public function testAVerdictSetWithoutOpeningIsRefused(): void {
		$event = new ObjectUpdatingEvent(
			$this->entity(['caseRef' => 'case-x', 'documentRef' => 'doc-1', 'classification' => 'openbaar']),
			$this->entity(['caseRef' => 'case-x', 'documentRef' => 'doc-1']),
		);

		$this->guard()->handle(event: $event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(WooPagesSeenGuard::ERROR_CODE, $event->getErrors()['code']);
		$this->assertSame([1, 2, 3], $event->getErrors()['pages']);
		$this->assertStringContainsString('1, 2, 3', $event->getErrors()['message']);

		$created = new ObjectCreatingEvent($this->entity(['caseRef' => 'case-x', 'documentRef' => 'doc-1', 'classification' => 'niet_openbaar']));
		$this->guard()->handle(event: $created);
		$this->assertTrue($created->isPropagationStopped());
	}//end testAVerdictSetWithoutOpeningIsRefused()

	/**
	 * Once every required page is seen the verdict passes, and a page seen by anyone counts.
	 *
	 * @return void
	 */
	public function testAVerdictAfterEveryPagePasses(): void {
		$this->store->rows['wooDocumentReview']['review-1']['pagesSeen'] = [
			['page' => 1, 'by' => 'a'], ['page' => 2, 'by' => 'b'], ['page' => 3, 'by' => 'a'],
		];
		$event = new ObjectCreatingEvent($this->entity(['caseRef' => 'case-x', 'documentRef' => 'doc-1', 'classification' => 'openbaar']));

		$this->guard()->handle(event: $event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAVerdictAfterEveryPagePasses()

	/**
	 * An unchanged verdict, another schema, an out-of-scope document and a document without a review all pass.
	 *
	 * @return void
	 */
	public function testWritesThatSetNoNewVerdictOnAnInScopeDocumentPass(): void {
		$events = [
			new ObjectUpdatingEvent(
				$this->entity(['caseRef' => 'case-x', 'documentRef' => 'doc-1', 'classification' => 'openbaar', 'note' => 'typo']),
				$this->entity(['caseRef' => 'case-x', 'documentRef' => 'doc-1', 'classification' => 'openbaar']),
			),
			new ObjectCreatingEvent($this->entity(['caseRef' => 'case-x', 'documentRef' => 'doc-1', 'classification' => 'openbaar'], '7')),
			new ObjectCreatingEvent($this->entity(['caseRef' => 'case-x', 'documentRef' => 'doc-2', 'classification' => 'openbaar'])),
			new ObjectCreatingEvent($this->entity(['caseRef' => 'case-x', 'documentRef' => 'doc-9', 'classification' => 'openbaar'])),
			new ObjectCreatingEvent($this->entity(['caseRef' => 'case-x', 'documentRef' => 'doc-1'])),
		];
		foreach ($events as $n => $event) {
			$this->guard()->handle(event: $event);
			$this->assertFalse($event->isPropagationStopped(), 'event '.$n);
		}
	}//end testWritesThatSetNoNewVerdictOnAnInScopeDocumentPass()

	/**
	 * The registrar binds the guard to OpenRegister's creating and updating events.
	 *
	 * @return void
	 */
	public function testTheRegistrarBindsTheGuard(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$bound = [];
		$context->method('registerEventListener')->willReturnCallback(
			static function (string $event, string $listener) use (&$bound): void {
				$bound[] = [$event, $listener];
			}
		);

		(new WooListenerRegistrar())->register(context: $context);

		$this->assertContains([ObjectCreatingEvent::class, WooPagesSeenGuard::class], $bound);
		$this->assertContains([ObjectUpdatingEvent::class, WooPagesSeenGuard::class], $bound);
	}//end testTheRegistrarBindsTheGuard()
}//end class
