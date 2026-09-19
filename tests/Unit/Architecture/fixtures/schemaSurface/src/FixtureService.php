<?php

/**
 * A fixture reader, so the scanner has something to find.
 *
 * It also mentions mentionedInProse here as a bare word inside a sentence,
 * which is the toestemming case in miniature: a word in a comment is not a
 * reader, and a scanner that counted it would call a dark schema reachable.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Architecture\Fixtures\SchemaSurface;

/**
 * Reads one schema by name.
 */
class FixtureService {
	/**
	 * The slug this service reads.
	 *
	 * @return string The slug.
	 */
	public function slug(): string {
		return 'quotedInCode';
	}//end slug()

	/**
	 * A schema fetched by URL rather than named on its own.
	 *
	 * The `case-location` shape: the slug is the last segment of an
	 * OpenRegister object URL and appears nowhere else. `fetched` is a
	 * deliberate PREFIX of it and must NOT come out reachable off this line.
	 *
	 * @return string The URL.
	 */
	public function url(): string {
		return '/apps/openregister/api/objects/dossiq/fetchedByUrl';
	}//end url()
}//end class
