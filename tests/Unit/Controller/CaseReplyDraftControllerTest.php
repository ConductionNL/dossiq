<?php

/**
 * A reply to the resident can be kept as a draft per user and case, read and
 * written only by a reader of the case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 * @spec openspec/specs/case-reply-draft/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\CaseReplyDraftController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\CaseReplyDraftController
 */
class CaseReplyDraftControllerTest extends TestCase {

	/**
	 * The stored user values, by key.
	 *
	 * @var array<string, string>
	 */
	private array $stored = [];

	/**
	 * The controller for user handler-1, who may or may not read the case.
	 *
	 * @param bool $read Whether the user may read the case.
	 *
	 * @return CaseReplyDraftController
	 */
	private function controller(bool $read = true): CaseReplyDraftController {
		$guard = $this->createMock(originalClassName: CaseAccessGuard::class);
		$guard->method('hasCaseReadAccess')->willReturn(value: $read);
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn(value: 'handler-1');
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn(value: $user);
		$config = $this->createMock(originalClassName: IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			callback: fn (string $uid, string $app, string $key, mixed $default = ''): string => $this->stored[$uid.'|'.$key] ?? (string)$default
		);
		$config->method('setUserValue')->willReturnCallback(
			callback: function (string $uid, string $app, string $key, string $value): void {
				$this->stored[$uid.'|'.$key] = $value;
			}
		);
		$config->method('deleteUserValue')->willReturnCallback(
			callback: function (string $uid, string $app, string $key): void {
				unset($this->stored[$uid.'|'.$key]);
			}
		);
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnArgument(argumentIndex: 0);

		return new CaseReplyDraftController(
			request: $this->createMock(originalClassName: IRequest::class),
			caseAccessGuard: $guard,
			userSession: $session,
			config: $config,
			l10n: $l10n,
		);
	}//end controller()

	/**
	 * A saved draft is read back by the same user on the same case.
	 *
	 * @return void
	 */
	public function testASavedDraftIsReadBack(): void {
		$controller = $this->controller();
		$saved = $controller->save(id: 'case-1', text: 'De stukken zijn verzameld.');
		self::assertSame(expected: Http::STATUS_OK, actual: $saved->getStatus());

		$read = $controller->show(id: 'case-1')->getData();
		self::assertSame(expected: 'De stukken zijn verzameld.', actual: $read['text']);
		self::assertNotSame(expected: '', actual: $read['savedAt']);
		self::assertSame(expected: '', actual: $controller->show(id: 'case-2')->getData()['text']);
	}//end testASavedDraftIsReadBack()

	/**
	 * An empty draft clears it.
	 *
	 * @return void
	 */
	public function testAnEmptyDraftClearsIt(): void {
		$controller = $this->controller();
		$controller->save(id: 'case-1', text: 'Iets');
		$controller->save(id: 'case-1', text: '  ');

		self::assertSame(expected: [], actual: $this->stored);
		self::assertSame(expected: '', actual: $controller->show(id: 'case-1')->getData()['text']);
	}//end testAnEmptyDraftClearsIt()

	/**
	 * Someone who may not read the case can neither read nor write a draft on it.
	 *
	 * @return void
	 */
	public function testANonReaderIsRefusedBeforeAnythingIsReadOrWritten(): void {
		$controller = $this->controller(read: false);

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->show(id: 'case-1')->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->save(id: 'case-1', text: 'x')->getStatus());
		self::assertSame(expected: [], actual: $this->stored);
	}//end testANonReaderIsRefusedBeforeAnythingIsReadOrWritten()

	/**
	 * A draft longer than the limit is refused, not cut.
	 *
	 * @return void
	 */
	public function testATooLongDraftIsRefused(): void {
		$response = $this->controller()->save(id: 'case-1', text: str_repeat(string: 'a', times: 20001));

		self::assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
		self::assertSame(expected: [], actual: $this->stored);
	}//end testATooLongDraftIsRefused()
}//end class
