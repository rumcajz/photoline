<?php
/**
 * Photos Timeline
 *
 * @license AGPL-3.0
 */

namespace OCA\photos_timeline\Migrations;

use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\ISimpleMigration;

/**
 * 1.1.0: videos can have thumbnails now (from ffmpeg or from playback).
 * Videos that 1.0 marked "no thumbnail possible" become pending again; the
 * background job then sorts them into ffmpeg work or "waiting for playback".
 */
class Version20261108000000 implements ISimpleMigration {
	/** @var IDBConnection */
	private $db;

	public function __construct(?IDBConnection $db = null) {
		$this->db = $db ?? \OC::$server->getDatabaseConnection();
	}

	public function run(IOutput $out) {
		$qb = $this->db->getQueryBuilder();
		$qb->update('photos_timeline')
			->set('thumb_state', $qb->createNamedParameter(0))
			->where($qb->expr()->eq('kind', $qb->createNamedParameter(2)))
			->andWhere($qb->expr()->eq('thumb_state', $qb->createNamedParameter(2)));
		$n = $qb->execute();
		$out->info("Photos Timeline: $n videos queued for thumbnails");
	}
}
