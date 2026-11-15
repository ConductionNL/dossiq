<?php

/**
 * The uploader is the signed-in caller with upload access, else the 401 or 403.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Controller\Support
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\FindsCaseUploader;
use OCA\Dossiq\Kernel\Contracts\DossierUploadHandlerInterface;
use OCP\AppFramework\Http;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\FindsCaseUploader
 */
class FindsCaseUploaderTest extends TestCase {

	/**
	 * A controller over the given caller and access.
	 *
	 * @param IUser|null $user   The signed-in user.
	 * @param bool       $access Whether uploading to the case is allowed.
	 *
	 * @return object
	 */
	private function controller(?IUser $user, bool $access): object {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$handler = $this->createMock(DossierUploadHandlerInterface::class);
		$handler->method('hasCaseUploadAccess')->willReturn($access);

		return new class ($session, $handler) {
			use FindsCaseUploader;

			public function __construct(
				private readonly IUserSession $userSession,
				private readonly DossierUploadHandlerInterface $uploadHandler,
			) {
			}

			public function who(): mixed {
				return $this->uploader(caseId: 'c1');
			}
		};
	}

	public function testUploader(): void {
		$user = $this->createMock(IUser::class);

		$this->assertSame($user, $this->controller(user: $user, access: true)->who());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller(user: $user, access: false)->who()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(user: null, access: true)->who()->getStatus());
	}
}
