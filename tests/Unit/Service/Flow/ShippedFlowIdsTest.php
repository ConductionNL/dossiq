<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/documents-on-the-case/tasks.md#3.3
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Listener\ShippedFlowIdsListener;
use OCA\Dossiq\Service\Flow\ShippedFlowAdoption;
use OCA\Dossiq\Service\Flow\ShippedFlowIds;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use PHPUnit\Framework\TestCase;

/**
 * A shipped flow's per-instance uuid reaches the page under the key the manifest names.
 *
 * @covers \OCA\Dossiq\Service\Flow\ShippedFlowIds
 * @covers \OCA\Dossiq\Listener\ShippedFlowIdsListener
 */
class ShippedFlowIdsTest extends TestCase {

	/**
	 * The reader over a census answering these flows.
	 *
	 * @param array<string, mixed> $census What the flow store holds.
	 *
	 * @return ShippedFlowIds The reader.
	 */
	private function ids(array $census): ShippedFlowIds {
		$adoption = $this->createMock(ShippedFlowAdoption::class);
		$adoption->method('census')->willReturn($census);

		return new ShippedFlowIds($adoption);
	}//end ids()

	/**
	 * The stored flow's uuid is answered under its key; other flows are not.
	 *
	 * @return void
	 */
	public function testAStoredFlowIsAnsweredUnderItsKey(): void {
		$ids = $this->ids([
			'available' => true,
			'note' => '',
			'flows' => [
				['uuid' => 'uuid-case', 'name' => 'Case behandeling', 'enabled' => false, 'owner' => ''],
				['uuid' => 'uuid-generate', 'name' => 'Generate a document', 'enabled' => false, 'owner' => ''],
			],
		]);

		self::assertSame(['generate_document_flow' => 'uuid-generate'], $ids->resolve());
	}//end testAStoredFlowIsAnsweredUnderItsKey()

	/**
	 * No flow store, or no stored flow, leaves the slot unset rather than empty.
	 *
	 * @return void
	 */
	public function testAnAbsentFlowLeavesTheSlotUnset(): void {
		self::assertSame([], $this->ids(['available' => false, 'note' => 'none', 'flows' => []])->resolve());
		self::assertSame([], $this->ids(['available' => true, 'note' => '', 'flows' => []])->resolve());
	}//end testAnAbsentFlowLeavesTheSlotUnset()

	/**
	 * A logged-in dossiq page gets the uuid in its initial state, where `@resolve:` reads it.
	 *
	 * @return void
	 */
	public function testADossiqPageCarriesTheUuid(): void {
		self::assertSame(['generate_document_flow' => 'uuid-generate'], $this->provided(app: 'dossiq', loggedIn: true));
	}//end testADossiqPageCarriesTheUuid()

	/**
	 * Another app's page, and a public page, get nothing.
	 *
	 * @return void
	 */
	public function testOtherPagesGetNothing(): void {
		self::assertSame([], $this->provided(app: 'files', loggedIn: true));
		self::assertSame([], $this->provided(app: 'dossiq', loggedIn: false));
	}//end testOtherPagesGetNothing()

	/**
	 * What the listener puts into initial state for a page of this app.
	 *
	 * @param string $app The rendering app.
	 * @param bool $loggedIn Whether a user is logged in.
	 *
	 * @return array<string, mixed> Every initial state provided.
	 */
	private function provided(string $app, bool $loggedIn): array {
		$provided = [];
		$initialState = $this->createMock(IInitialState::class);
		$initialState->method('provideInitialState')->willReturnCallback(
			static function (string $key, mixed $data) use (&$provided): void {
				$provided[$key] = $data;
			}
		);

		$listener = new ShippedFlowIdsListener(
			$this->ids([
				'available' => true,
				'note' => '',
				'flows' => [['uuid' => 'uuid-generate', 'name' => 'Generate a document', 'enabled' => false, 'owner' => '']],
			]),
			$initialState,
		);
		$listener->handle(new BeforeTemplateRenderedEvent($loggedIn, new TemplateResponse($app, 'index')));
		$listener->handle(new \OCP\EventDispatcher\Event());

		return $provided;
	}//end provided()
}//end class
