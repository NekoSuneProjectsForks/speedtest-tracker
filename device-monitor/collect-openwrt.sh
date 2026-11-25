#!/bin/sh
# Run on an OpenWrt router with nlbwmon installed. Output to stdout.
set -eu
exec nlbw -c json -g mac
