<?php
/**
 * PhotoLine
 *
 * @license AGPL-3.0
 */

namespace OCA\photos_timeline\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use OCP\Migration\ISchemaMigration;

/**
 * The timeline index: one row per photo or video per user.
 */
class Version20261107000000 implements ISchemaMigration {
	public function changeSchema(Schema $schema, array $options) {
		$prefix = $options['tablePrefix'];
		if ($schema->hasTable("{$prefix}photos_timeline")) {
			return;
		}
		$t = $schema->createTable("{$prefix}photos_timeline");
		$t->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'unsigned' => true]);
		$t->addColumn('user_id', Types::STRING, ['length' => 64]);
		$t->addColumn('file_id', Types::BIGINT, ['unsigned' => true]);
		$t->addColumn('etag', Types::STRING, ['length' => 40]);
		$t->addColumn('size', Types::BIGINT, ['default' => 0]);
		$t->addColumn('path', Types::STRING, ['length' => 4000]);
		$t->addColumn('name', Types::STRING, ['length' => 250]);
		$t->addColumn('mime', Types::STRING, ['length' => 100]);
		// 1 = photo, 2 = video
		$t->addColumn('kind', Types::SMALLINT, ['default' => 1]);
		// Capture time as local wall-clock seconds (EXIF has no time zone)
		$t->addColumn('taken', Types::BIGINT, ['default' => 0]);
		// 1 = EXIF, 2 = file name, 3 = video metadata, 4 = modification time
		$t->addColumn('date_source', Types::SMALLINT, ['default' => 4]);
		$t->addColumn('width', Types::INTEGER, ['default' => 0]);
		$t->addColumn('height', Types::INTEGER, ['default' => 0]);
		$t->addColumn('duration', Types::INTEGER, ['default' => 0]);
		// Average colour as 0xRRGGBB, -1 while unknown
		$t->addColumn('color', Types::INTEGER, ['default' => -1]);
		// 0 = pending, 1 = ready, 2 = not possible, 3 = being made
		$t->addColumn('thumb_state', Types::SMALLINT, ['default' => 0]);
		$t->setPrimaryKey(['id']);
		$t->addUniqueIndex(['user_id', 'file_id'], 'ptl_user_file');
		$t->addIndex(['user_id', 'taken'], 'ptl_user_taken');
		$t->addIndex(['user_id', 'thumb_state'], 'ptl_user_thumb');
	}
}
