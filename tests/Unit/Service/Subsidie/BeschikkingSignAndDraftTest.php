<?php

/**
 * BeschikkingService: signing, the state-aid category of a draft, and a failing save.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Subsidie
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-001-multi-year-beschikking-with-voorschot-schema
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Subsidie;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\BeschikkingService;
use OCA\Dossiq\Service\Subsidie\StaatssteunClassifier;
use OCA\Dossiq\Service\Subsidie\SubsidieService;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectPatch;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Signing derives the signer from the session; a draft keeps a declared state-aid category.
 *
 * @covers \OCA\Dossiq\Service\Subsidie\BeschikkingService
 * @uses   \OCA\Dossiq\Service\Subsidie\SubsidieSchemaLookup
 * @uses   \OCA\Dossiq\Service\Subsidie\SubsidieService
 * @uses   \OCA\Dossiq\Service\Subsidie\StaatssteunClassifier
 */
class BeschikkingSignAndDraftTest extends TestCase {

	/**
	 * An in-memory object service that records patches and saves.
	 *
	 * @param bool $fail Whether every write throws.
	 *
	 * @return object The stub.
	 */
	private static function objectService(bool $fail = false): object {
		return new class($fail) {
			/**
			 * Patches received.
			 *
			 * @var array<int, ObjectPatch>
			 */
			public array $patches = [];

			/**
			 * Objects saved.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $saved = [];

			/**
			 * Constructor.
			 *
			 * @param bool $fail Whether every write throws.
			 */
			public function __construct(private readonly bool $fail) {
			}

			/**
			 * Apply a partial update.
			 *
			 * @param ObjectPatch $patch The patch.
			 *
			 * @return array<string, mixed> The patched row.
			 */
			public function patchObject(ObjectPatch $patch): array {
				if ($this->fail === true) {
					throw new RuntimeException('store down');
				}

				$this->patches[] = $patch;
				return array_merge(['id' => $patch->objectId], $patch->data);
			}

			/**
			 * Save a whole object.
			 *
			 * @param array<string, mixed> $object  The object.
			 * @param ObjectSaveOptions    $options The target.
			 *
			 * @return array<string, mixed> The stored row.
			 */
			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions()): array {
				if ($this->fail === true) {
					throw new RuntimeException('disk full');
				}

				$this->saved[] = $object;
				return array_merge(['id' => 'b-1'], $object);
			}
		};
	}//end objectService()

	/**
	 * A configured settings service over the given object service.
	 *
	 * @param object $objectService The object service.
	 *
	 * @return SettingsService The mock.
	 */
	private function settings(object $objectService): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'subsidie_beschikking_schema' => 'beschikking',
				default => $default,
			}
		);

		return $settings;
	}//end settings()

	/**
	 * The service.
	 *
	 * @param object                     $objectService The object service.
	 * @param IUser|null                 $user          The session user.
	 * @param LoggerInterface|null       $logger        The logger.
	 * @param StaatssteunClassifier|null $classifier    The state-aid classifier.
	 *
	 * @return BeschikkingService The service.
	 */
	private function service(
		object $objectService,
		?IUser $user = null,
		?LoggerInterface $logger = null,
		?StaatssteunClassifier $classifier = null,
	): BeschikkingService {
		$settings = $this->settings(objectService: $objectService);
		$logger ??= $this->createMock(LoggerInterface::class);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new BeschikkingService(
			settingsService: $settings,
			subsidyService: new SubsidieService($settings, $logger),
			userSession: $session,
			logger: $logger,
			stateAid: ($classifier ?? new StaatssteunClassifier()),
		);
	}//end service()

	/**
	 * A user with a uid.
	 *
	 * @param string $uid The uid.
	 *
	 * @return IUser The mock.
	 */
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}//end user()

	/**
	 * Signing without a session user is refused before anything is read.
	 *
	 * @return void
	 */
	public function testSigningWithoutASessionUserIsRefused(): void {
		$store = self::objectService();

		try {
			$this->service(objectService: $store)->sign(decisionId: 'b-1');
			$this->fail('Expected the signature to be refused.');
		} catch (OCSBadRequestException $e) {
			$this->assertSame(expected: 'Authenticatie vereist om te ondertekenen', actual: $e->getMessage());
		}

		$this->assertSame(expected: [], actual: $store->patches);
	}//end testSigningWithoutASessionUserIsRefused()

	/**
	 * The signature patches only signedBy (the session uid) and signedOn onto the beschikking.
	 *
	 * @return void
	 */
	public function testSigningPatchesTheSessionUserAndTheMoment(): void {
		$store = self::objectService();
		$before = new DateTimeImmutable('-1 second');

		$result = $this->service(objectService: $store, user: $this->user(uid: 'jdevries'))->sign(decisionId: 'b-7');

		$this->assertCount(expectedCount: 1, haystack: $store->patches);
		$patch = $store->patches[0];
		$this->assertSame(expected: 'b-7', actual: $patch->objectId);
		$this->assertSame(expected: 'dossiq', actual: $patch->target->register);
		$this->assertSame(expected: 'beschikking', actual: $patch->target->schema);
		$this->assertSame(expected: ['signedBy', 'signedOn'], actual: array_keys($patch->data));
		$this->assertSame(expected: 'jdevries', actual: $patch->data['signedBy']);
		$signedOn = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $patch->data['signedOn']);
		$this->assertNotFalse(condition: $signedOn);
		$this->assertGreaterThanOrEqual($before->getTimestamp(), $signedOn->getTimestamp());
		$this->assertSame(expected: 'b-7', actual: $result['id']);
		$this->assertSame(expected: 'jdevries', actual: $result['signedBy']);
	}//end testSigningPatchesTheSessionUserAndTheMoment()

	/**
	 * A failing patch is logged and answered with a neutral refusal.
	 *
	 * @return void
	 */
	public function testAFailingSignatureIsLoggedAndRefused(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('Dossiq subsidie: sign beschikking failed: store down');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Kon beschikking niet ondertekenen');

		$this->service(objectService: self::objectService(fail: true), user: $this->user(uid: 'jdevries'), logger: $logger)
			->sign(decisionId: 'b-1');
	}//end testAFailingSignatureIsLoggedAndRefused()

	/**
	 * A state-aid category the handler typed wins over the classifier.
	 *
	 * @return void
	 */
	public function testADeclaredStateAidCategoryIsKept(): void {
		$store = self::objectService();
		$classifier = $this->createMock(StaatssteunClassifier::class);
		$classifier->expects($this->never())->method('classify');

		$created = $this->service(objectService: $store, classifier: $classifier)->createDraft(
			requestId: 'aanvraag-1',
			payload: ['grantedAmount' => 1000, 'stateAidCategory' => ' agvv '],
			sequence: 4
		);

		$this->assertSame(expected: 'agvv', actual: $created['stateAidCategory']);
		$this->assertSame(expected: 'aanvraag-1', actual: $store->saved[0]['subsidieaanvraag']);
		$this->assertSame(expected: 'draft', actual: $store->saved[0]['status']);
		$this->assertMatchesRegularExpression(pattern: '/^SUB-\d{4}-000004$/', string: $store->saved[0]['beschikkingnummer']);
	}//end testADeclaredStateAidCategoryIsKept()

	/**
	 * Without a declared category the classifier decides, from the amounts, the AGVV article and DAEB flag.
	 *
	 * @return void
	 */
	public function testTheClassifierReceivesTheAmountsTheArticleAndTheDaebFlag(): void {
		$classifier = $this->createMock(StaatssteunClassifier::class);
		$classifier->expects($this->once())
			->method('classify')
			->with(2500.0, 100.0, 'art. 25', true)
			->willReturn('daeb');

		$created = $this->service(objectService: self::objectService(), classifier: $classifier)->createDraft(
			requestId: 'aanvraag-2',
			payload: ['grantedAmount' => 2500, 'eerdereDeMinimis' => 100, 'agvvArtikel' => ' art. 25 ', 'isDaeb' => true],
			sequence: 1
		);

		$this->assertSame(expected: 'daeb', actual: $created['stateAidCategory']);
	}//end testTheClassifierReceivesTheAmountsTheArticleAndTheDaebFlag()

	/**
	 * A blank AGVV article reaches the classifier as no article at all.
	 *
	 * @return void
	 */
	public function testABlankArticleIsNoArticle(): void {
		$classifier = $this->createMock(StaatssteunClassifier::class);
		$classifier->expects($this->once())
			->method('classify')
			->with(500.0, 0.0, null, false)
			->willReturn('de-minimis');

		$this->service(objectService: self::objectService(), classifier: $classifier)->createDraft(
			requestId: 'aanvraag-3',
			payload: ['grantedAmount' => 500, 'agvvArtikel' => '  '],
			sequence: 1
		);
	}//end testABlankArticleIsNoArticle()

	/**
	 * A failing save is logged and answered with a neutral refusal.
	 *
	 * @return void
	 */
	public function testAFailingDraftSaveIsLoggedAndRefused(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with('Dossiq subsidie: createDraft beschikking failed: disk full');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Kon beschikking niet aanmaken');

		$this->service(objectService: self::objectService(fail: true), logger: $logger)->createDraft(
			requestId: 'aanvraag-4',
			payload: ['grantedAmount' => 100],
			sequence: 1
		);
	}//end testAFailingDraftSaveIsLoggedAndRefused()
}//end class
