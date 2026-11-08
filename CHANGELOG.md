# Changelog

## Unreleased

- Marketplace metadata in `appinfo/info.xml`: website, issue tracker,
  repository, documentation links and screenshots (generated demo images).
- `make-package.sh` builds the Marketplace package (run-time files only),
  signed with `ocsign` when a key and certificate are given.

## 1.1.8

- Viewer: the arrow keys and Escape keep working after switching to another
  browser tab and back (focus could land outside the viewer).

## 1.1.7

- New name: **PhotoLine** (menu, page title, Files button, notices, logs, docs).
  The app id stays `photos_timeline`, so settings, index and thumbnails are kept.
- occ commands renamed to `photoline:index|thumbnails|status|purge`; the old
  `photos-timeline:…` names remain as aliases.

## 1.1.6

- Escape and the arrow keys work in the viewer also after the video player's
  own buttons were used (Chrome's player kept the keys).

## 1.1.0

- Video thumbnails: made with ffmpeg when the server has it (ownCloud's
  `preview_ffmpeg_path` setting or the PATH); otherwise the first frame shown
  when someone plays the video is kept as its thumbnail.
- Disabling the app removes its background job; enabling it again adds it back.
- `occ photos-timeline:purge --confirm` now removes everything (table,
  thumbnails, settings, background job) and leaves the app uninstalled.
- Admins see a notice when background jobs are not run by cron.
- `occ photos-timeline:status` shows the background job mode and the video
  thumbnail method.

## 1.0.1

- Full-screen view about 5x faster: JPEG photos are decoded at a reduced scale and
  sent as JPEG without a second resize.
- The first year label of the scrubber stays inside the rail.
- README: Marketplace publishing steps (code signing).

## 1.0.0

First release: My timeline with selectable folders and duplicate hiding, a
timeline for any folder from the Files app, year/month scrubber, photo/video and
date filters, full-screen viewer, background index and thumbnails.
