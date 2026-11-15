<?php

/**
 * The neutral fixture record kind for the frozen-record tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\FrozenRecord
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\FrozenRecord;

use OCA\Dossiq\Service\FrozenRecord\FreezeDefinition;

/**
 * A record kind called `fixtureRecord` that freezes at `issued`. It stands for no procedure.
 */
final class FrozenRecordFixture {

	/**
	 * The fixture freeze entry, as a package would configure it.
	 *
	 * @var array<string, mixed>
	 */
	public const ENTRY = [
		'schema' => 'fixtureRecord',
		'frozenStatuses' => ['issued', 'archived'],
		'fields' => ['body', 'amount'],
		'sealFields' => ['body', 'amount', 'reference'],
		'successor' => ['series' => 'fixture', 'defaultPrefix' => 'F'],
	];

	/**
	 * The fixture entry as a definition.
	 *
	 * @param array<string, mixed> $override Keys to replace.
	 *
	 * @return FreezeDefinition The definition.
	 */
	public static function definition(array $override = []): FreezeDefinition {
		return new FreezeDefinition(entry: array_merge(self::ENTRY, $override));
	}//end definition()

	/**
	 * A fixture record.
	 *
	 * @param string $status The status.
	 * @param array<string, mixed> $extra More fields.
	 *
	 * @return array<string, mixed> The record payload.
	 */
	public static function record(string $status, array $extra = []): array {
		return array_merge(
			[
				'id' => 'rec-1',
				'currentStatus' => $status,
				'body' => 'The text',
				'amount' => 10,
				'reference' => 'F-2026-000001',
				'note' => 'free',
				'@self' => ['schema' => 'fixtureRecord', 'id' => 'rec-1', 'deleted' => null],
			],
			$extra
		);
	}//end record()
}//end class
