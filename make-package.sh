#!/bin/sh
# Builds the Marketplace package: build/package.tar.gz with one top-level
# folder, photos_timeline/, holding only the files the app needs at run time.
#
#   ./make-package.sh                         unsigned (for testing)
#   ./make-package.sh KEY LEAF.crt INTER.crt  signed with ocsign
#
# Nothing may change inside build/photos_timeline after signing.
set -eu
cd "$(dirname "$0")"
ID=photos_timeline
VERSION=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml | head -n 1)
OUT=build/$ID

rm -rf build
mkdir -p "$OUT"
# runtime files only: no tests, screenshots, build scripts or git files
for f in appinfo css img js lib templates COPYING README.md CHANGELOG.md; do
	cp -R "$f" "$OUT/"
done
find "$OUT" -name '.DS_Store' -delete

if [ $# -ge 2 ]; then
	ocsign --path "$OUT" --key "$1" --cert "$2" ${3:+--chain "$3"}
	echo "signed: $OUT/appinfo/signature.json"
else
	echo "NOT signed (pass KEY CERT [CHAIN] to sign)"
fi

tar -czf build/package.tar.gz -C build "$ID"
echo "build/package.tar.gz ($ID $VERSION)"
tar -tzf build/package.tar.gz | sed -n '1,200p' | grep -c '' | xargs echo "files:"
