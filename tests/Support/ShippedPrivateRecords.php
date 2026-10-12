<?php

/**
 * The people on a case against the register as it ships
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
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

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\Access\ObjectReadGrants;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Service\SettingsService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who reads and who updates a private record of a case, from what the register ships.
 *
 * Three real parts and one model. Real: the merged register the importer
 * reads (a case type's `reviewerAccess` and each record schema's
 * authorization block), CaseReviewerAccess, and CaseRecordReaders under it.
 * Model: admits() decides as OpenRegister's
 * `PermissionHandler::privateScopeVerdict()` does on a private object (owner,
 * administrator, owning group, or a grant that carries the action), with the
 * schema's group list as the ceiling of a grant. The model is a reading of
 * OpenRegister, not OpenRegister: the refusal on a running instance is the
 * live check of the change that uses this.
 */
class ShippedPrivateRecords {

	/**
	 * The merged register.
	 *
	 * @var array<string, mixed>
	 */
	private array $merged = [];

	/**
	 * The rows of the case.
	 *
	 * @var InMemoryRegister
	 */
	public InMemoryRegister $rows;

	/**
	 * What the reconcile granted: permission bits by record uuid and user.
	 *
	 * @var array<string, array<string, int>>
	 */
	public array $grants = [];

	/**
	 * The owning group the reconcile set, by record uuid.
	 *
	 * @var array<string, string>
	 */
	public array $ownerGroups = [];

	/**
	 * The groups each user is in.
	 *
	 * @var array<string, list<string>>
	 */
	public array $groups = [];

	/**
	 * Read the register as the importer does.
	 *
	 * @param TestCase $test The test, for its mocks.
	 */
	public function __construct(private readonly TestCase $test) {
		$settings = dirname(__DIR__, 2) . '/lib/Settings';
		$base = json_decode((string)file_get_contents($settings . '/dossiq_register.json'), true);
		[$this->merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $settings . '/register.d');
		$this->rows = new InMemoryRegister();
	}//end __construct()

	/**
	 * The shipped case type row with this slug.
	 *
	 * @param string $slug The case type slug.
	 *
	 * @return array<string, mixed>
	 */
	public function caseType(string $slug): array {
		foreach (($this->merged['components']['objects'] ?? []) as $object) {
			if (($object['@self']['schema'] ?? '') === 'caseType' && ($object['@self']['slug'] ?? '') === $slug) {
				return $object;
			}
		}

		return [];
	}//end caseType()

	/**
	 * Reconcile one case of the shipped case type and keep what was granted.
	 *
	 * @param string $caseTypeSlug The case type slug.
	 * @param string $caseId       The case uuid; its row must be seeded under `case`.
	 *
	 * @return array{status: string, granted: int, revoked: int, refused: int}
	 */
	public function reconcile(string $caseTypeSlug, string $caseId): array {
		$caseType = $this->caseType(slug: $caseTypeSlug);
		// Each schema setting answers its own slug, so a row is seeded under the slug the register ships.
		$config = (['register' => 'dossiq'] + array_flip(SchemaSlugMap::SLUG_TO_CONFIG_KEY));

		$settings = $this->mock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->rows);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => (string)($config[$key] ?? $default));

		$resolver = $this->mock(CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn($caseType);

		$grants = $this->mock(ObjectReadGrants::class);
		$grants->method('available')->willReturn(true);
		$grants->method('reconcile')->willReturnCallback(
			function (string $schema, string $uuid, array $readers, string $group): array {
				$this->grants[$uuid] = [];
				foreach ($readers as $user => $permissions) {
					$this->grants[$uuid][(string)$user] = $permissions;
				}

				$this->ownerGroups[$uuid] = $group;
				return ['granted' => count($readers), 'revoked' => 0, 'refused' => 0];
			}
		);

		$groupManager = $this->mock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array('admin', ($this->groups[$uid] ?? []), true));
		$groupManager->method('isInGroup')->willReturnCallback(fn (string $uid, string $group): bool => in_array($group, ($this->groups[$uid] ?? []), true));

		$access = new CaseReviewerAccess(
			settingsService: $settings,
			caseTypes: new CaseTypeStore($settings),
			resolver: $resolver,
			grants: $grants,
			groupManager: $groupManager,
			logger: $this->mock(LoggerInterface::class),
		);

		return $access->reconcile(caseId: $caseId);
	}//end reconcile()

	/**
	 * Whether OpenRegister admits this user on this record, as the model reads its rule.
	 *
	 * @param string $schema The record's schema slug in the shipped register.
	 * @param string $uuid   The record uuid.
	 * @param string $user   The user.
	 * @param string $action `read` or `update`.
	 *
	 * @return bool
	 */
	public function admits(string $schema, string $uuid, string $user, string $action): bool {
		$groups = ($this->groups[$user] ?? []);
		if (in_array('admin', $groups, true) === true) {
			return true;
		}

		$authorization = (array)($this->merged['components']['schemas'][$schema]['authorization'] ?? []);
		$ceiling = (array_intersect($groups, (array)($authorization[$action] ?? [])) !== []);
		if (($authorization['scope'] ?? '') !== 'private') {
			return $ceiling;
		}

		$owner = (string)(($this->rows->row(schema: $schema, uuid: $uuid)['@self'] ?? [])['owner'] ?? '');
		if ($owner === $user || in_array(($this->ownerGroups[$uuid] ?? ''), $groups, true) === true) {
			return true;
		}

		$bit = ['read' => 1, 'update' => 2][$action];
		$granted = ((($this->grants[$uuid][$user] ?? 0) & $bit) === $bit);

		// A grant re-opens the record within the schema's own rule, never beyond it.
		return $granted === true && $ceiling === true;
	}//end admits()

	/**
	 * A mock from the test that owns this fixture.
	 *
	 * @param class-string $class The class.
	 *
	 * @return \PHPUnit\Framework\MockObject\MockObject
	 */
	private function mock(string $class): object {
		return (fn (string $class): object => $this->createMock($class))->call($this->test, $class);
	}//end mock()
}//end class
