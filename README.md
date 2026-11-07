# Photos Timeline for ownCloud

A fast, view-only timeline of your photos and videos, grouped by day, with a
year and month scrubber for jumping through the years.

- **My timeline**: one timeline made from the folders you choose (by default
  `CameraUpload`). When the same photo is in several of them (same name and
  size), it is shown once, from the folder highest in your list.
- **Any folder**: the button next to Gallery's in the Files app opens the
  timeline of the folder you are in, including its subfolders.
- **Filters**: photos, videos or both; optional from and to dates.
- **Full-screen view** with keyboard (arrows, Esc) and swipe navigation, "Show in
  Files" and "Download original".
- **View only**: the app never uploads, changes, moves, deletes or shares files.

## Why it is fast

- A small index table (`<prefix>photos_timeline`) holds the date, size and shape
  of every photo and video, so opening a timeline is a single query.
- Thumbnails (a 320 px and a 48 px WebP per photo) are made in the background, so
  browsing never waits for image processing. The browser receives them in packs
  of up to 60 (300 for the small placeholders) and keeps them in its cache storage.
- Only the part of the grid on screen is drawn.

## Security

- Every route needs a logged-in ownCloud user; there are no public routes and no
  share links. All API calls need ownCloud's CSRF token.
- The user id always comes from the session. Every file id is checked against
  that user's own index *and* their file system (`getById` on their own folder)
  before a thumbnail or preview is returned.
- Thumbnails are stored in `<datadirectory>/appdata_photos_timeline/<user>/`,
  outside the web root and outside ownCloud's file cache.
- The browser only receives id, date, size, type, file name and folder of each
  item. Location and other EXIF data are never sent.
- Thumbnails are cached in the browser's cache storage under a per-user name and
  are removed when you log out from the app's page.

## Requirements

- ownCloud 11, PHP 8.3 with GD (WebP support) and exif. Imagick with WebP
  support is used when available and is much faster (scaled JPEG decoding);
  GD is the fallback.
- Background jobs in **cron** mode are strongly recommended (admins see a
  notice in the app otherwise).
- Optional: **ffmpeg** for video thumbnails (see below). Browsers play MP4,
  WebM and most MOV files directly, so no video tool is needed for playback.

## Video thumbnails

- **With ffmpeg** on the server (ownCloud's `preview_ffmpeg_path` system setting,
  or `ffmpeg` on the PATH), the background job takes a frame one second into
  each video (the first frame for very short videos). ffmpeg is not shipped
  with the app.
- **Without ffmpeg**, a video shows a play tile until someone plays it in the
  timeline: the first frame the browser shows is then kept as its thumbnail.
  The server accepts such a frame only for the user's own video that has no
  thumbnail yet, only as a small JPEG, PNG or WebP image, and it decodes and
  re-encodes it before storing it. Videos the browser cannot play keep the play
  tile.
- If ffmpeg is installed later, videos still waiting for playback are picked up
  by the next background run.

## Background work

A background job runs every 5 minutes. It updates the index (only when something
in a user's files changed) and makes thumbnails for each user's selected folders:
up to 240 seconds per run between 01:00 and 06:00, 30 seconds per run otherwise.
Photos outside the selected folders get their thumbnails the first time they are
viewed.

App settings (`occ config:app:set photos_timeline <key> --value=<n>`):

| Key | Default | Meaning |
|---|---|---|
| `night_start`, `night_end` | 1, 6 | Hours of the night window |
| `budget_night`, `budget_day` | 240, 30 | Seconds of thumbnail work per run |

Commands:

```
occ photos-timeline:index [user...] [--force]
occ photos-timeline:thumbnails [user...] [--all-folders] [--time=SECONDS] [--retry-failed]
occ photos-timeline:status
occ photos-timeline:purge --confirm
```

For a large library, run the first thumbnail pass by hand, at low priority:

```
nice -n 19 occ photos-timeline:thumbnails
```

Folders containing a `.nomedia` or `.noimage` file are left out, as in Gallery.

## Disabling and removing the app

- **Disabling** the app stops it and removes its background job. The index and
  thumbnails are kept, so enabling it again is instant (the job is added back).
- **Removing everything**:

  ```
  occ photos-timeline:purge --confirm
  ```

  deletes the thumbnail cache, drops the index table, deletes users' and the
  app's settings, removes the background job and leaves the app disabled and
  uninstalled. Then delete the app folder, or enable the app again to start
  from scratch. Users' files are never touched.

## Tests

```
sudo -u www-data php apps/photos_timeline/tests/run.php [userA userB ...]
```

Runs unit checks, verifies that no route is public or skips the CSRF check, and
with two or more user ids checks that no user can get another user's thumbnails,
previews, timeline items or folders. It only reads data.

## Publishing to the ownCloud Marketplace

Since ownCloud 11, apps must be code-signed; unsigned apps are blocked when they
are installed or enabled from the Marketplace. To publish:

1. Request a code-signing certificate for the app id `photos_timeline` in the
   `owncloud/developer-certificates` repository (issue form with your CSR and
   the source repository).
2. Sign the release with `ocsign` and build `package.tar.gz` (the app folder
   without `tests/`, `_ref/` and `.git`).
3. Open a pull request to `owncloud/marketplace` adding
   `apps/photos_timeline/releases/<version>/package.tar.gz` (Git LFS, signed
   commits with a DCO `Signed-off-by` line).

Before that, add `<website>`, `<bugs>` and `<repository>` to `appinfo/info.xml`
and a screenshot.

## License

AGPL-3.0, see `COPYING`.
