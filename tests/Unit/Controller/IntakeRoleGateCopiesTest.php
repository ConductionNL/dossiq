<?php

/**
 * The intake role gate of the mail intake and intake triage controllers, allowed and refused.
 *
 * Both controllers refuse a caller who is not signed in with 401
 * `unauthenticated`, a caller the intake policy does not allow with 403
 * `Not authorized`, and let an intake worker through. Both read the caller's
 * uid as '' when nobody is signed in. These pin every one of those answers on
 * both controllers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\IntakeTriageController;
use OCA\Dossiq\Controller\MailIntakeController;
use OCA\Dossiq\Service\Email\IntakePolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class IntakeRoleGateCopiesTest extends TestCase {

	/**
	 * Both controllers.
	 *
	 * @return array<string, array{0: class-string}> Per copy: the controller class.
	 */
	public static function copies(): array {
		return [
			'mail intake'    => [MailIntakeController::class],
			'intake triage'  => [IntakeTriageController::class],
		];
	}//end copies()

	/**
	 * A controller with only its session and intake policy, as signed in by $uid.
	 *
	 * @param class-string $class The controller class.
	 * @param string|null  $uid   Who is signed in, null for nobody.
	 *
	 * @return object The controller.
	 */
	private function controller(string $class, ?string $uid): object {
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(originalClassName: IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$policy = $this->createMock(originalClassName: IntakePolicy::class);
		$policy->method('mayRunIntake')->willReturnCallback(static fn (string $userId): bool => $userId === 'intaker');

		$controller = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		(function () use ($session, $policy): void {
			$this->userSession = $session;
			$this->policy = $policy;
		})->call($controller);

		return $controller;
	}//end controller()

	/**
	 * What a private method of the controller answers.
	 *
	 * @param object $controller The controller.
	 * @param string $method     The method.
	 *
	 * @return mixed The answer.
	 */
	private function call(object $controller, string $method): mixed {
		return (new ReflectionMethod($controller, $method))->invoke($controller);
	}//end call()

	/**
	 * The gate's answer as [status, body], or null when it lets the caller through.
	 *
	 * @param class-string $class The controller class.
	 * @param string|null  $uid   Who is signed in.
	 *
	 * @return array{0: int, 1: mixed}|null The refusal, or null.
	 */
	private function gate(string $class, ?string $uid): ?array {
		$answer = $this->call(controller: $this->controller(class: $class, uid: $uid), method: 'requireIntakeRole');
		if ($answer === null) {
			return null;
		}

		self::assertInstanceOf(JSONResponse::class, $answer);
		return [$answer->getStatus(), $answer->getData()];
	}//end gate()

	/**
	 * Nobody signed in is 401, a caller without the intake role is 403, an intake worker passes.
	 *
	 * @param class-string $class The controller class.
	 *
	 * @return void
	 *
	 * @dataProvider copies
	 */
	public function testTheGateRefusesAndAllows(string $class): void {
		self::assertSame([401, ['message' => 'unauthenticated']], $this->gate(class: $class, uid: null));
		self::assertSame([403, ['message' => 'Not authorized']], $this->gate(class: $class, uid: 'burger'));
		self::assertSame([403, ['message' => 'Not authorized']], $this->gate(class: $class, uid: ''));
		self::assertNull($this->gate(class: $class, uid: 'intaker'));
	}//end testTheGateRefusesAndAllows()

	/**
	 * The caller's uid, or '' when nobody is signed in.
	 *
	 * @param class-string $class The controller class.
	 *
	 * @return void
	 *
	 * @dataProvider copies
	 */
	public function testTheCallerIdIsTheSignedInUid(string $class): void {
		self::assertSame('', $this->call(controller: $this->controller(class: $class, uid: null), method: 'callerId'));
		self::assertSame('intaker', $this->call(controller: $this->controller(class: $class, uid: 'intaker'), method: 'callerId'));
	}//end testTheCallerIdIsTheSignedInUid()
}//end class
