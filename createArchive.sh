#!/bin/sh

VERSION=`git describe --tags --always`

GIT_TOP_LEVEL=`git rev-parse --show-toplevel`
OUTDIR=`readlink -e "$GIT_TOP_LEVEL/.."`

OUTPATH="$OUTDIR/gedeon-sync-$VERSION.zip"

echo "Creating archive $OUTPATH"

git archive --format zip --prefix=gedeon-sync/ --output "$OUTPATH" master
