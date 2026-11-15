<?php

/**
 * A signed-out caller is a 401, one outside the audience a 403, one inside passes.
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

use OCA\Dossiq\Controller\Support\RefusesOutsideReportAudience;
use OCA\Dossiq\Kernel\Contracts\ReportingAudienceInterface;
use OCP\AppFramework\Http;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Controller\Support\RefusesOutsideReportAudience
 */
class RefusesOutsideReportAudienceTest extends TestCase {

	/**
	 * A report controller over the given caller and audience answer.
	 *
	 * @param IUser|null $user     The signed-in user.
	 * @param bool       $audience Whether the user is in the audience.
	 *
	 * @return object
	 */
	private function controller(?IUser $user, bool $audience): object {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$reporting = $this->createMock(ReportingAudienceInterface::class);
		$reporting->method('isInAudience')->willReturn($audience);

		return new class ($session, $reporting) {
			use RefusesOutsideReportAudience;

			public function __construct(
				private readonly IUserSession $userSession,
				private readonly ReportingAudienceInterface $audience,
			) {
			}

			public function refusal(): mixed {
				return $this->audienceRefusal();
			}
		};
	}

	public function testRefusals(): void {
		$user = $this->createMock(IUser::class);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(user: null, audience: true)->refusal()->getStatus());
		$outside = $this->controller(user: $user, audience: false)->refusal();
		$this->assertSame(Http::STATUS_FORBIDDEN, $outside->getStatus());
		$this->assertSame(['message' => ReportingAudienceInterface::REFUSAL], $outside->getData());
		$this->assertNull($this->controller(user: $user, audience: true)->refusal());
	}
}
