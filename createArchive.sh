#!/bin/sh

VERSION=`git describe --tags --always`

GIT_TOP_LEVEL=`git rev-parse --show-toplevel`
OUTDIR=`readlink -e "$GIT_TOP_LEVEL/.."`

OUTPATH="$OUTDIR/wp-realestate-sync-$VERSION.zip"

echo "Creating archive $OUTPATH"

git archive --format zip --prefix=wp-realestate-sync/ --output "$OUTPATH" master
