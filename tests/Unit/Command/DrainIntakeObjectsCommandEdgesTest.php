<?php

/**
 * DrainIntakeObjectsCommand edges: nothing configured to drain, a request that
 * already holds a case, a submit that refuses (with and without findings), and
 * the admin the drain runs as when no one is signed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Command;

use OCA\Dossiq\Command\DrainIntakeObjectsCommand;
use OCA\Dossiq\Service\Intake\IntakeCaseTypes;
use OCA\Dossiq\Service\Intake\IntakeObjectDrain;
use OCA\Dossiq\Service\SettingsService;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @covers \OCA\Dossiq\Command\DrainIntakeObjectsCommand
 * @uses   \OCA\Dossiq\Service\Intake\IntakeObjectDrain
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class DrainIntakeObjectsCommandEdgesTest extends TestCase {

	/**
	 * Settings the command reads.
	 *
	 * @var array<string, string>
	 */
	private array $settings = ['register' => '3', 'case_schema' => '5', 'portaal_verzoek_schema' => '9'];

	/**
	 * Portal requests, the rows of schema 9.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $requests = [['id' => 'pv-1', 'kind' => 'bezwaarschrift', 'rationale' => 'Niet eens']];

	/**
	 * Cases, the rows of schema 5.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $cases = [];

	/**
	 * What the form submit does: null answers a case, a Throwable is thrown.
	 */
	private ?\Throwable $submitFails = null;

	/**
	 * What the container answers for the form submit: 'submitter', 'missing' or 'not-an-object'.
	 */
	private string $formSubmit = 'submitter';

	private ?IUser $signedIn = null;

	/**
	 * The admin group's users; null means there is no admin group.
	 *
	 * @var array<int, IUser>|null
	 */
	private ?array $admins = null;

	private object $objects;

	private object $submitter;

	private ?IUser $boundTo = null;

	protected function setUp(): void {
		$this->signedIn = $this->createMock(IUser::class);
	}

	/**
	 * The command over the doubles this test configured.
	 *
	 * @return CommandTester
	 */
	private function tester(): CommandTester {
		$test = $this;
		$this->objects = new class($test) {
			/**
			 * Every save, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			public function __construct(private readonly DrainIntakeObjectsCommandEdgesTest $test) {
			}

			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$schema = ($query['@self']['schema'] ?? null);
				if ($schema === 9) {
					return $this->test->requests;
				}

				$wanted = (string)($query['externalReference'] ?? '');
				return array_values(array_filter($this->test->cases, static fn (array $case): bool => ($case['externalReference'] ?? '') === $wanted));
			}

			/**
			 * @param array<string, mixed> $object The object.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, string $register, string $schema, string $uuid): array {
				$this->saved[] = ['object' => $object, 'schema' => $schema, 'uuid' => $uuid];
				return $object;
			}
		};

		$fails = $this->submitFails;
		$this->submitter = new class($fails) {
			/**
			 * Every submit, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $submits = [];

			public function __construct(private readonly ?\Throwable $fails) {
			}

			/**
			 * @param array<string, string> $destination Where the case goes.
			 * @param array<string, mixed>  $payload     The case.
			 *
			 * @return array<string, string>
			 */
			public function submit(array $destination, ?array $mapping, array $payload, ?IUser $subject = null): array {
				$this->submits[] = ['payload' => $payload, 'subject' => $subject];
				if ($this->fails !== null) {
					throw $this->fails;
				}

				return ['id' => 'case-new'];
			}
		};

		$settingsService = $this->createMock(SettingsService::class);
		$settingsService->method('getConfigValue')->willReturnCallback(
			fn (string $key, string $default = ''): string => ($this->settings[$key] ?? $default)
		);
		$settingsService->method('getObjectService')->willReturn($this->objects);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): mixed {
				return match ($this->formSubmit) {
					'missing' => throw new RuntimeException('not found'),
					'not-an-object' => 'a string',
					default => $this->submitter,
				};
			}
		);

		$caseTypes = $this->createMock(IntakeCaseTypes::class);
		$caseTypes->method('caseTypeFor')->willReturn('aaaaaaaa-1111-4111-a111-111111111111');

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->signedIn);
		$session->method('setUser')->willReturnCallback(function (?IUser $user): void {
			$this->boundTo = $user;
		});

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(function (string $gid): ?IGroup {
			if ($gid !== 'admin' || $this->admins === null) {
				return null;
			}

			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn($this->admins);
			return $group;
		});

		return new CommandTester(
			new DrainIntakeObjectsCommand(
				container: $container,
				settingsService: $settingsService,
				drain: new IntakeObjectDrain(caseTypes: $caseTypes),
				userSession: $session,
				groupManager: $groups,
			)
		);
	}

	public function testWithoutAPortalRequestSchemaThereIsNothingToDrain(): void {
		$this->settings['portaal_verzoek_schema'] = '';
		$tester = $this->tester();

		$this->assertSame(Command::SUCCESS, $tester->execute(['--apply' => true]));
		$this->assertSame("No portaalVerzoek schema is configured. Nothing to drain.\n", $tester->getDisplay());
		$this->assertSame([], $this->submitter->submits);
	}

	public function testWithoutTheDossiqRegisterItRefusesToDrain(): void {
		$this->settings['case_schema'] = '';
		$tester = $this->tester();

		$this->assertSame(Command::FAILURE, $tester->execute(['--apply' => true]));
		$this->assertStringContainsString('the dossiq register are needed to drain', $tester->getDisplay());
	}

	public function testAFormSubmitThatIsNotAnObjectCountsAsMissing(): void {
		$this->formSubmit = 'not-an-object';
		$tester = $this->tester();

		$this->assertSame(Command::FAILURE, $tester->execute([]));
		$this->assertStringContainsString('form submit', $tester->getDisplay());
	}

	/**
	 * A request whose case exists but which was never marked is marked now,
	 * and opens no second case.
	 *
	 * @return void
	 */
	public function testARequestThatAlreadyHoldsACaseIsMarkedAndNotSubmittedAgain(): void {
		$this->cases = [['id' => 'case-old', 'externalReference' => 'pv-1']];
		$tester = $this->tester();

		$this->assertSame(Command::SUCCESS, $tester->execute(['--apply' => true]));
		$this->assertSame([], $this->submitter->submits);
		$this->assertSame('pv-1', $this->objects->saved[0]['uuid']);
		$this->assertSame('case-old', $this->objects->saved[0]['object']['newCaseId']);
		$this->assertStringContainsString('Delivered 0, already a case 1, refused 0, pending 0.', $tester->getDisplay());
	}

	public function testARequestWithoutAnIdIsNotLookedUpAsACase(): void {
		$this->requests = [['kind' => 'bezwaarschrift', 'rationale' => 'Zonder id']];
		$this->cases = [['id' => 'case-old', 'externalReference' => '']];
		$tester = $this->tester();

		$tester->execute([]);

		$this->assertStringContainsString('Delivered 1, already a case 0, refused 0, pending 1.', $tester->getDisplay());
	}

	public function testARefusedSubmitIsReportedWithItsMessageAsTheCode(): void {
		$this->submitFails = new RuntimeException('schema validation failed');
		$tester = $this->tester();

		$this->assertSame(Command::FAILURE, $tester->execute(['--apply' => true]));
		$this->assertStringContainsString('refused pv-1 :refused', $tester->getDisplay());
		$this->assertStringContainsString('Delivered 0, already a case 0, refused 1, pending 1.', $tester->getDisplay());
		$this->assertSame([], $this->objects->saved, 'a refused request is not marked');
	}

	public function testARefusalCarryingFindingsReportsEachOne(): void {
		$this->submitFails = new class('refused') extends RuntimeException {
			/**
			 * @return array<int, array<string, string>>
			 */
			public function getFindings(): array {
				return [
					['property' => 'description', 'code' => 'required'],
					['property' => 'caseType', 'code' => 'unknown'],
				];
			}
		};
		$tester = $this->tester();

		$tester->execute(['--apply' => true]);

		$this->assertStringContainsString('refused pv-1 description:required, caseType:unknown', $tester->getDisplay());
	}

	public function testAnExceptionWithAnEmptyFindingsListFallsBackToItsMessage(): void {
		$this->submitFails = new class('no detail') extends RuntimeException {
			/**
			 * @return array<int, array<string, string>>
			 */
			public function getFindings(): array {
				return [];
			}
		};
		$tester = $this->tester();

		$tester->execute(['--apply' => true]);

		$this->assertStringContainsString('refused pv-1 :refused', $tester->getDisplay());
	}

	public function testWithoutASessionTheDrainRunsAsTheFirstAdmin(): void {
		$this->signedIn = null;
		$first = $this->createMock(IUser::class);
		$first->method('getUID')->willReturn('beheerder');
		$this->admins = [$first, $this->createMock(IUser::class)];
		$tester = $this->tester();

		$this->assertSame(Command::SUCCESS, $tester->execute(['--apply' => true]));
		$this->assertSame($first, $this->boundTo);
		$this->assertSame($first, $this->submitter->submits[0]['subject'], 'the case is submitted as that admin');
		$this->assertStringContainsString('Running as admin user "beheerder".', $tester->getDisplay());
	}

	public function testWithoutAnAdminTheDrainRefuses(): void {
		$this->signedIn = null;
		$this->admins = [];
		$tester = $this->tester();

		$this->assertSame(Command::FAILURE, $tester->execute(['--apply' => true]));
		$this->assertStringContainsString('No admin user found to run the drain under.', $tester->getDisplay());
		$this->assertNull($this->boundTo);

		$this->admins = null;
		$tester = $this->tester();
		$this->assertSame(Command::FAILURE, $tester->execute(['--apply' => true]));
		$this->assertSame([], $this->submitter->submits);
	}
}
