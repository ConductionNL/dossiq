<?php

/**
 * The verify route needs case read access, and only answers the case's own sets; the occ command shares the service.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Command\VerifyWooDeliveredSetCommand;
use OCA\Dossiq\Controller\WooDeliveredSetController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Woo\WooDeliveredSetFiles;
use OCA\Dossiq\Woo\WooDeliveredSetVerifier;
use OCA\Dossiq\Woo\WooDeliveredSetWriter;
use OCP\Files\IRootFolder;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * REQ-WDS-003 through the route and the command.
 *
 * @covers \OCA\Dossiq\Controller\WooDeliveredSetController
 * @covers \OCA\Dossiq\Command\VerifyWooDeliveredSetCommand
 *
 * @uses \OCA\Dossiq\Woo\WooDeliveredSetVerifier
 * @uses \OCA\Dossiq\Woo\WooDeliveredSetWriter
 * @uses \OCA\Dossiq\Woo\WooCaseDocuments
 * @uses \OCA\Dossiq\Woo\WooDeliveredSetFiles
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class WooDeliveredSetControllerTest extends TestCase {

	/**
	 * The verifier over one frozen set of case-1.
	 *
	 * @var WooDeliveredSetVerifier
	 */
	private WooDeliveredSetVerifier $verifier;

	/**
	 * Reads both files of an item.
	 *
	 * @var WooDeliveredSetFiles
	 */
	private WooDeliveredSetFiles $files;

	/**
	 * The set id.
	 *
	 * @var string
	 */
	private string $setId = '';

	/**
	 * Write one set.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$store = new InMemoryRegister();
		$store->seed(schema: 'document', uuid: 'doc-1', row: ['fileName' => 'brief.pdf', 'content' => base64_encode('brief')]);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'document_schema' => 'document'][$key] ?? $default)
		);
		$writer = new WooDeliveredSetWriter(settings: $settings);
		$this->setId = $writer->idOf(row: $writer->open(caseId: 'case-1', decisionId: 'dec-1', delivered: [
			['assessment' => 'as-1', 'classification' => 'openbaar', 'deliveredRef' => 'doc-1', 'originalRef' => 'doc-1', 'content' => base64_encode('brief')],
		]));
		$documents = new WooCaseDocuments(settingsService: $settings, rootFolder: $this->createMock(IRootFolder::class), logger: new NullLogger());
		$this->verifier = new WooDeliveredSetVerifier(
			settings: $settings,
			sets: $writer,
			documents: $documents,
		);
		$this->files = new WooDeliveredSetFiles(documents: $documents);
	}//end setUp()

	/**
	 * The controller for a user with or without read access.
	 *
	 * @param bool $mayRead Whether the guard grants read access.
	 *
	 * @return WooDeliveredSetController
	 */
	private function controller(bool $mayRead): WooDeliveredSetController {
		$guard = $this->createMock(CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturn($mayRead);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));

		return new WooDeliveredSetController(
			appName: 'dossiq',
			request: $this->createMock(IRequest::class),
			verifier: $this->verifier,
			guard: $guard,
			userSession: $session,
			files: $this->files,
		);
	}//end controller()

	/**
	 * A user without case read access gets 403 and no hashes.
	 *
	 * @return void
	 */
	public function testVerifyRefusesAUserWithoutCaseAccess(): void {
		$response = $this->controller(mayRead: false)->verify(id: 'case-1', setId: $this->setId);

		$this->assertSame(403, $response->getStatus());
		$this->assertArrayNotHasKey('items', $response->getData());
	}//end testVerifyRefusesAUserWithoutCaseAccess()

	/**
	 * A reader gets the item statuses; another case's set is 404.
	 *
	 * @return void
	 */
	public function testVerifyAnswersTheItemStatuses(): void {
		$response = $this->controller(mayRead: true)->verify(id: 'case-1', setId: $this->setId);

		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['verified']);
		$this->assertSame('match', $response->getData()['items'][0]['status']);
		$this->assertSame(404, $this->controller(mayRead: true)->verify(id: 'case-2', setId: $this->setId)->getStatus());
	}//end testVerifyAnswersTheItemStatuses()

	/**
	 * A store that throws answers 503, never a verified set.
	 *
	 * @return void
	 */
	public function testAnUnreadableStoreAnswers503(): void {
		$broken = new class {
			/**
			 * Every read fails.
			 *
			 * @return never
			 */
			public function find(): never {
				throw new \RuntimeException('database gone');
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($broken);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => (['register' => 'dossiq'][$key] ?? $default));
		$this->verifier = new WooDeliveredSetVerifier(
			settings: $settings,
			sets: new WooDeliveredSetWriter(settings: $settings),
			documents: new WooCaseDocuments(settingsService: $settings, rootFolder: $this->createMock(IRootFolder::class), logger: new NullLogger()),
		);

		$this->assertSame(503, $this->controller(mayRead: true)->verify(id: 'case-1', setId: 'set-1')->getStatus());
	}//end testAnUnreadableStoreAnswers503()

	/**
	 * The occ command prints every item and succeeds only on a verified set.
	 *
	 * @return void
	 */
	public function testTheCommandVerifiesTheSameSet(): void {
		$tester = new CommandTester(new VerifyWooDeliveredSetCommand(verifier: $this->verifier));

		$this->assertSame(0, $tester->execute(['setId' => $this->setId]));
		$this->assertStringContainsString('match  doc-1', $tester->getDisplay());
		$this->assertSame(1, $tester->execute(['setId' => 'no-such-set']));
	}//end testTheCommandVerifiesTheSameSet()

	/**
	 * The compare dialog's two reads need case read access, like verify.
	 *
	 * @return void
	 */
	public function testTheCompareReadsRefuseAUserWithoutCaseAccess(): void {
		$controller = $this->controller(mayRead: false);

		$this->assertSame(403, $controller->item(id: 'case-1', setId: $this->setId, index: 0)->getStatus());
		$this->assertSame(403, $controller->file(id: 'case-1', setId: $this->setId, index: 0, side: 'original')->getStatus());
	}//end testTheCompareReadsRefuseAUserWithoutCaseAccess()

	/**
	 * A reader gets both files named, and the bytes of each; another case's set and an unknown item are 404.
	 *
	 * @return void
	 */
	public function testTheCompareReadsAnswerBothFiles(): void {
		$controller = $this->controller(mayRead: true);

		$item = $controller->item(id: 'case-1', setId: $this->setId, index: 0);
		$this->assertSame(200, $item->getStatus());
		$this->assertSame('brief.pdf', $item->getData()['original']['fileName']);
		$this->assertSame('application/pdf', $item->getData()['delivered']['mimeType']);

		$file = $controller->file(id: 'case-1', setId: $this->setId, index: 0, side: 'delivered');
		$this->assertInstanceOf(DataDownloadResponse::class, $file);
		$this->assertSame('brief', $file->render());

		$this->assertSame(404, $controller->item(id: 'case-2', setId: $this->setId, index: 0)->getStatus());
		$this->assertSame(404, $controller->item(id: 'case-1', setId: $this->setId, index: 3)->getStatus());
		$this->assertSame(404, $controller->file(id: 'case-1', setId: $this->setId, index: 3, side: 'original')->getStatus());
	}//end testTheCompareReadsAnswerBothFiles()
}//end class
