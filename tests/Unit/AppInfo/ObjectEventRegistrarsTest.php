<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\AppInfo;

use OCA\Dossiq\Listener\CaseMarkerDerivationListener;
use OCA\Dossiq\Listener\CaseRequesterProjectionListener;
use OCA\Dossiq\Listener\ForfeitInvoiceListener;
use OCA\Dossiq\Listener\LinkMarkerListener;
use OCA\Dossiq\Listener\PagesSeenGuard;
use OCA\Dossiq\Listener\Registrar\CaseLinkListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\CaseMarkerListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\CaseRequesterListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\ForfeitInvoiceListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\ReviewListenerRegistrar;
use OCA\Dossiq\Listener\Registrar\WooListenerRegistrar;
use OCA\Dossiq\Listener\StoppingRuleGuard;
use OCA\Dossiq\Listener\WooReportsInitialStateListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectLeavingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use PHPUnit\Framework\TestCase;

/**
 * Each small object-event registrar attaches exactly its listener to exactly
 * its events, at its declared priority, and registers no service.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\AppInfo
 *
 * @covers \OCA\Dossiq\Listener\Registrar\CaseLinkListenerRegistrar
 * @covers \OCA\Dossiq\Listener\Registrar\CaseMarkerListenerRegistrar
 * @covers \OCA\Dossiq\Listener\Registrar\CaseRequesterListenerRegistrar
 * @covers \OCA\Dossiq\Listener\Registrar\ForfeitInvoiceListenerRegistrar
 * @covers \OCA\Dossiq\Listener\Registrar\ReviewListenerRegistrar
 * @covers \OCA\Dossiq\Listener\Registrar\WooListenerRegistrar
 */
class ObjectEventRegistrarsTest extends TestCase {
	use RecordsRegistrations;

	/**
	 * Link markers follow both a create and an update of an object.
	 *
	 * @return void
	 */
	public function testTheLinkMarkerFollowsCreatesAndUpdates(): void {
		(new CaseLinkListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(
			expected: [
				[ObjectCreatedEvent::class, LinkMarkerListener::class, 0],
				[ObjectUpdatedEvent::class, LinkMarkerListener::class, 0],
			],
			actual: $this->listeners
		);
		self::assertSame(expected: [], actual: $this->services);
	}//end testTheLinkMarkerFollowsCreatesAndUpdates()

	/**
	 * Marker derivation runs on the pre-save events, late, at its declared priority.
	 *
	 * @return void
	 */
	public function testMarkerDerivationRunsBeforeSaveAtItsPriority(): void {
		(new CaseMarkerListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(expected: -300, actual: CaseMarkerListenerRegistrar::DERIVATION_PRIORITY);
		self::assertSame(
			expected: [
				[ObjectCreatingEvent::class, CaseMarkerDerivationListener::class, -300],
				[ObjectUpdatingEvent::class, CaseMarkerDerivationListener::class, -300],
			],
			actual: $this->listeners
		);
		self::assertSame(expected: [], actual: $this->services);
	}//end testMarkerDerivationRunsBeforeSaveAtItsPriority()

	/**
	 * The requester projection runs on the pre-save events at the default priority.
	 *
	 * @return void
	 */
	public function testTheRequesterProjectionRunsBeforeSave(): void {
		(new CaseRequesterListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(expected: 0, actual: CaseRequesterListenerRegistrar::PROJECTION_PRIORITY);
		self::assertSame(
			expected: [
				[ObjectCreatingEvent::class, CaseRequesterProjectionListener::class, 0],
				[ObjectUpdatingEvent::class, CaseRequesterProjectionListener::class, 0],
			],
			actual: $this->listeners
		);
		self::assertSame(expected: [], actual: $this->services);
	}//end testTheRequesterProjectionRunsBeforeSave()

	/**
	 * The forfeit invoice listens to updates only.
	 *
	 * @return void
	 */
	public function testTheForfeitInvoiceListensToUpdatesOnly(): void {
		(new ForfeitInvoiceListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(expected: [[ObjectUpdatedEvent::class, ForfeitInvoiceListener::class, 0]], actual: $this->listeners);
		self::assertSame(expected: [], actual: $this->services);
	}//end testTheForfeitInvoiceListensToUpdatesOnly()

	/**
	 * Both review guards attach, each to its own set of pre-save events, in order.
	 *
	 * @return void
	 */
	public function testBothReviewGuardsAttachToTheirEvents(): void {
		(new ReviewListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(
			expected: [
				[ObjectCreatingEvent::class, PagesSeenGuard::class, 0],
				[ObjectUpdatingEvent::class, PagesSeenGuard::class, 0],
				[ObjectUpdatingEvent::class, StoppingRuleGuard::class, 0],
				[ObjectDeletingEvent::class, StoppingRuleGuard::class, 0],
				[ObjectLeavingEvent::class, StoppingRuleGuard::class, 0],
			],
			actual: $this->listeners
		);
		self::assertSame(expected: [], actual: $this->services);
	}//end testBothReviewGuardsAttachToTheirEvents()

	/**
	 * The Woo reports state is provided when a template renders.
	 *
	 * @return void
	 */
	public function testWooReportsStateIsProvidedOnTemplateRender(): void {
		(new WooListenerRegistrar())->register(context: $this->recordingContext());

		self::assertSame(
			expected: [[BeforeTemplateRenderedEvent::class, WooReportsInitialStateListener::class, 0]],
			actual: $this->listeners
		);
		self::assertSame(expected: [], actual: $this->services);
	}//end testWooReportsStateIsProvidedOnTemplateRender()
}//end class
