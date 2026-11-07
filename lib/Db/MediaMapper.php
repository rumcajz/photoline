<?php
/**
 * PhotoLine
 *
 * @license AGPL-3.0
 */

namespace OCA\PhotosTimeline\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * All access to the app's own table. Every query is scoped to one user id,
 * which callers take from the session or the job, never from the request.
 */
class MediaMapper {
	public const TABLE = 'photos_timeline';

	public const KIND_PHOTO = 1;
	public const KIND_VIDEO = 2;

	public const THUMB_PENDING = 0;
	public const THUMB_READY = 1;
	public const THUMB_FAILED = 2;
	public const THUMB_WORKING = 3;
	/** A video without ffmpeg on the server: waits for a frame from a browser that plays it. */
	public const THUMB_FROM_PLAYBACK = 4;

	/** @var IDBConnection */
	private $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	public function getConnection(): IDBConnection {
		return $this->db;
	}

	/**
	 * Light rows for the indexer: file id => [etag, path, id, thumb_state].
	 */
	public function getIndexState(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file_id', 'etag', 'path', 'thumb_state')
			->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$res = $qb->execute();
		$out = [];
		while ($row = $res->fetchAssociative()) {
			$out[(int)$row['file_id']] = $row;
		}
		$res->free();
		return $out;
	}

	public function insert(string $userId, array $m): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values($this->params($qb, $m + ['user_id' => $userId]));
		$qb->execute();
	}

	/**
	 * Full metadata update after the file content changed; thumbnails are redone.
	 */
	public function update(string $userId, int $fileId, array $m): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE);
		foreach ($this->params($qb, $m) as $col => $p) {
			$qb->set($col, $p);
		}
		$qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$qb->execute();
	}

	public function updatePath(string $userId, int $fileId, string $path, string $name): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('path', $qb->createNamedParameter($path))
			->set('name', $qb->createNamedParameter($name))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		$qb->execute();
	}

	/**
	 * @param int[] $fileIds
	 */
	public function deleteFiles(string $userId, array $fileIds): void {
		foreach (\array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete(self::TABLE)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$qb->execute();
		}
	}

	public function deleteUser(string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->execute();
	}

	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE);
		$qb->execute();
	}

	/**
	 * @return string[] user ids that have rows
	 */
	public function getUsers(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')->from(self::TABLE);
		$res = $qb->execute();
		$out = [];
		while ($row = $res->fetchAssociative()) {
			$out[] = $row['user_id'];
		}
		$res->free();
		return $out;
	}

	/**
	 * Timeline rows for a view, newest first.
	 *
	 * @param string[] $prefixes folder paths relative to the user's files ('' = everything)
	 * @param int[] $kinds
	 */
	public function findForTimeline(string $userId, array $prefixes, array $kinds, ?int $from, ?int $to): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'etag', 'size', 'path', 'name', 'mime', 'kind', 'taken', 'width', 'height', 'duration', 'color', 'thumb_state')
			->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('taken', 'DESC')
			->addOrderBy('file_id', 'DESC');
		if ($kinds && \count($kinds) < 2) {
			$qb->andWhere($qb->expr()->in('kind', $qb->createNamedParameter($kinds, IQueryBuilder::PARAM_INT_ARRAY)));
		}
		if ($from !== null) {
			$qb->andWhere($qb->expr()->gte('taken', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)));
		}
		if ($to !== null) {
			$qb->andWhere($qb->expr()->lte('taken', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT)));
		}
		if (!\in_array('', $prefixes, true)) {
			if (!$prefixes) {
				return [];
			}
			$or = [];
			foreach ($prefixes as $p) {
				$or[] = $qb->expr()->like('path', $qb->createNamedParameter($this->db->escapeLikeParameter($p) . '/%'));
			}
			$qb->andWhere(\call_user_func_array([$qb->expr(), 'orX'], $or));
		}
		$res = $qb->execute();
		$rows = $res->fetchAllAssociative();
		$res->free();
		return $rows;
	}

	/**
	 * @param int[] $fileIds
	 * @return array file id => row (only rows of this user)
	 */
	public function findByIds(string $userId, array $fileIds): array {
		$out = [];
		foreach (\array_chunk($fileIds, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')->from(self::TABLE)
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('file_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$res = $qb->execute();
			while ($row = $res->fetchAssociative()) {
				$out[(int)$row['file_id']] = $row;
			}
			$res->free();
		}
		return $out;
	}

	/**
	 * Rows still needing thumbnails, optionally limited to folder prefixes.
	 *
	 * @param string[]|null $prefixes null = all folders
	 */
	public function findPendingThumbs(string $userId, ?array $prefixes, int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'file_id', 'etag', 'path', 'mime', 'kind', 'duration', 'thumb_state')
			->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('thumb_state', $qb->createNamedParameter(self::THUMB_PENDING, IQueryBuilder::PARAM_INT)))
			->orderBy('taken', 'DESC')
			->setMaxResults($limit);
		if ($prefixes !== null && !\in_array('', $prefixes, true)) {
			if (!$prefixes) {
				return [];
			}
			$or = [];
			foreach ($prefixes as $p) {
				$or[] = $qb->expr()->like('path', $qb->createNamedParameter($this->db->escapeLikeParameter($p) . '/%'));
			}
			$qb->andWhere(\call_user_func_array([$qb->expr(), 'orX'], $or));
		}
		$res = $qb->execute();
		$rows = $res->fetchAllAssociative();
		$res->free();
		return $rows;
	}

	public function setThumbState(string $userId, int $fileId, int $state, ?int $color = null): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('thumb_state', $qb->createNamedParameter($state, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));
		if ($color !== null) {
			$qb->set('color', $qb->createNamedParameter($color, IQueryBuilder::PARAM_INT));
		}
		$qb->execute();
	}

	/**
	 * Thumbnails left "being made" by a crashed run are marked failed,
	 * so one bad file can never block the queue.
	 */
	public function failStuckThumbs(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('thumb_state', $qb->createNamedParameter(self::THUMB_FAILED, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('thumb_state', $qb->createNamedParameter(self::THUMB_WORKING, IQueryBuilder::PARAM_INT)));
		return (int)$qb->execute();
	}

	public function resetFailedThumbs(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('thumb_state', $qb->createNamedParameter(self::THUMB_PENDING, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('thumb_state', $qb->createNamedParameter(self::THUMB_FAILED, IQueryBuilder::PARAM_INT)));
		return (int)$qb->execute();
	}

	/**
	 * @return array{total:int, photos:int, videos:int, ready:int, pending:int, failed:int}
	 */
	/**
	 * Moves this user's videos without a thumbnail between "pending" (ffmpeg
	 * can make it) and "waiting for playback" (it cannot).
	 */
	public function switchVideoStates(string $userId, bool $ffmpeg): void {
		$from = $ffmpeg ? self::THUMB_FROM_PLAYBACK : self::THUMB_PENDING;
		$to = $ffmpeg ? self::THUMB_PENDING : self::THUMB_FROM_PLAYBACK;
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('thumb_state', $qb->createNamedParameter($to, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('thumb_state', $qb->createNamedParameter($from, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('kind', $qb->createNamedParameter(self::KIND_VIDEO, IQueryBuilder::PARAM_INT)));
		$qb->execute();
	}

	public function stats(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('kind', 'thumb_state')
			->selectAlias($qb->createFunction('COUNT(*)'), 'n')
			->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->groupBy('kind', 'thumb_state');
		$res = $qb->execute();
		$s = ['total' => 0, 'photos' => 0, 'videos' => 0, 'ready' => 0, 'pending' => 0, 'failed' => 0, 'playback' => 0];
		while ($row = $res->fetchAssociative()) {
			$n = (int)$row['n'];
			$s['total'] += $n;
			$s[(int)$row['kind'] === self::KIND_VIDEO ? 'videos' : 'photos'] += $n;
			$st = (int)$row['thumb_state'];
			if ($st === self::THUMB_READY) {
				$s['ready'] += $n;
			} elseif ($st === self::THUMB_FAILED) {
				$s['failed'] += $n;
			} elseif ($st === self::THUMB_FROM_PLAYBACK) {
				$s['playback'] += $n;
			} else {
				$s['pending'] += $n;
			}
		}
		$res->free();
		return $s;
	}

	private function params(IQueryBuilder $qb, array $m): array {
		$ints = ['file_id', 'size', 'kind', 'taken', 'date_source', 'width', 'height', 'duration', 'color', 'thumb_state'];
		$out = [];
		foreach ($m as $col => $v) {
			$out[$col] = \in_array($col, $ints, true)
				? $qb->createNamedParameter((int)$v, IQueryBuilder::PARAM_INT)
				: $qb->createNamedParameter((string)$v);
		}
		return $out;
	}
}
