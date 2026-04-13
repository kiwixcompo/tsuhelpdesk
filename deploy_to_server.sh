#!/bin/bash
# Run this from cPanel Terminal to deploy the helpdesk
# Usage: bash ~/repositories/tsuhelpdesk/deploy_to_server.sh

REPO=~/repositories/tsuhelpdesk
DEST=~/helpdesk.tsuniversity.ng

echo "Deploying TSU ICT Help Desk..."

# Pull latest from GitHub first
cd $REPO && git pull origin main

# Copy all files to the live directory
cp -rf $REPO/. $DEST/

# Copy hidden files (.htaccess etc)
cp $REPO/.htaccess $DEST/ 2>/dev/null || true

echo "Done. Files deployed to $DEST"
