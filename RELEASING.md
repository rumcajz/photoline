# Releasing YearRoll

Checklist for the maintainer. Releases on the ownCloud Marketplace are
immutable: a fix always goes out as a new version.

## Once: code-signing certificate

Since ownCloud 11, apps must be code-signed; unsigned apps are blocked when they
are installed or enabled. The certificate for the app id `photos_timeline` is
issued through the `owncloud/developer-certificates` repository (issue form with
the CSR and this repository; the bot asks for a challenge file in
`.well-known/`). Certificates are valid for two years. To renew, open a new
request for the same app id from this repository.

Signing needs three files, kept outside this repository:
the passphrase-protected private key `photos_timeline.key`, the certificate
`photos_timeline.crt` and ownCloud's `intermediate.crt`.

## Each release

1. Set `<version>` in `appinfo/info.xml` and date the version in
   `CHANGELOG.md`.
2. Build and sign the package (needs [`ocsign`](https://github.com/owncloud/ocsign)
   on the PATH):

   ```
   ./make-package.sh photos_timeline.key photos_timeline.crt intermediate.crt
   ```

   It copies only the run-time files to `build/photos_timeline/`, signs that copy
   and writes `build/package.tar.gz`. For a passphrase-protected key it asks for
   the passphrase and decrypts the key into a temporary file only for the
   signing step.
3. Test the package on a clean ownCloud 11: `occ integrity:check-app
   photos_timeline` must report no errors, and `tests/run.php` must pass.
4. Commit, tag (`vX.Y.Z`) and push.
5. Open a pull request to `owncloud/marketplace` adding
   `apps/photos_timeline/releases/<version>/package.tar.gz` (Git LFS) and
   optionally a `CHANGELOG.md` next to it. Commits there must be GPG-signed and
   carry a DCO `Signed-off-by` line (`git commit -s -S`).
