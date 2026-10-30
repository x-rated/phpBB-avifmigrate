# AVIF migration (slamb/avifmigrate)

**Version 0.9.3 (pre-release).** Companion of `slamb/avifattach`; 1.0.0 will be the first stable release.

One-off tool that converts images uploaded earlier to AVIF (GD, or Imagick when GD has no AVIF; quality 50; PNG/GIF/BMP and lossless WebP quality 90 = full-resolution 4:4:4 colour; still images only, EXIF/GPS dropped, EXIF orientation applied first).

- Install: copy `slamb/avifmigrate` into `ext/`, ACP -> Customise -> Manage extensions -> enable. Page: ACP -> Extensions -> AVIF migration.
- Needs PHP GD with AVIF (`imageavif`, `imagecreatefromavif`) and `avif` in an allowed extension group (Images).
- Always oldest first, N images per run. Each processed item is logged (also failed / skipped animated ones) and is never picked again.
- Attachments: file replaced in place (physical name has no extension), `real_filename` ext, `extension`, `mimetype`, `filesize` updated; thumbnail converted too (or switched off if that fails). Post text is not touched.
- Avatars (separate run): file converted in place but the name keeps its old extension, because `download/file.php` only serves png/gif/jpg/jpeg avatars; it sends the Content-Type from the file content, so the browser gets image/avif.
- **Originals are overwritten, there is no undo.** Back up before big runs.
- CLI: `php bin/phpbbcli.php avifmigrate:convert attachments --limit=5` / `--all`, same for `avatars`.
- Disable and delete the extension after the migration ("Delete data" drops the log table).

## Analysis step

`Analyze` (ACP) / `php bin/phpbbcli.php avifmigrate:analyze attachments|avatars [--limit=N|--all]` checks every
image without changing anything. Missing, non-image and undecodable files are marked **broken** and ignored
from then on; usable ones are marked **ready**. Conversion only takes usable images (broken ones found on the
way are marked the same way). Broken items are listed in the ACP with links to where they appeared.

## Review and delete

"Review and delete images" lists analysed attachments (broken / ready / all) with previews, links to where they
appear, checkboxes (shift-click selects a range) and a delete button. Deletion uses phpBB's own attachment manager
(file, thumbnail, DB record, post attachment counters) and asks for confirmation. Attachments only, not avatars.

## Thumbnails

If an attachment's thumbnail is missing or cannot be converted, a new AVIF thumbnail is created from the converted
image (so the post keeps the thumbnail with a click-to-enlarge link). Attachments converted by an earlier version
that lost their thumbnail can be repaired with `php bin/phpbbcli.php avifmigrate:thumbs` (only images wider than
the board's thumbnail width, and only if thumbnails are enabled).

## Interrupted runs

The ACP button stops after about 50 seconds (before a CDN/web server gateway timeout) and the rest is picked up by
the next click. If a run is killed anyway, items left as "pending" are recovered automatically on the next page
load / CLI run (after 10 minutes): a file already replaced by AVIF gets its database row finished, otherwise the
item is simply queued again. For big batches use the CLI.

The review page also has an Avatars view (previews, links to profiles, delete via the board's avatar manager: file removed, profile avatar cleared).

## 0.9.0: new workflow

- The ACP page starts with an environment check (GD AVIF, `avif` extension allowed, writable folders, free disk, EXIF, memory_limit).
- Nothing is pre-selected: choose the type, enter a count (or "everything that is left"), and the buttons are enabled. Convert asks for confirmation.
- Analysis and conversion run in small AJAX steps (about 20 s each) with a progress bar and a Stop button, so no gateway timeout (HTTP 504) can occur. The CLI stays available for very large runs.
- Valid images that GD cannot decode (e.g. CMYK JPEG) are no longer "broken": they get the status **Cannot be converted** and keep working. **Broken** now means: file missing, unreadable, or not an image at all.
- Previews in the results, in the recent list and in the review list (attachments and avatars); filters: ready, converted, skipped, cannot be converted, broken, failed.
- Statistics: total space saved (bytes and percent), per type, and an estimate for the images still ready.
- Status messages and the CLI are translated (English, Czech).

## Development
The workflow `.github/workflows/epv.yml` runs phpBB's Extension Pre Validator on every push (Actions tab > EPV).
