#!/usr/bin/env bash
# Charlottesville Glass — get the latest site from GitHub.
#
#   bash update.sh
#
# Safe to run any time. It pulls the newest code, and the first time it also
# makes sure the server has PHP (for the pay + admin pages) and that Apache
# is serving this folder's public/ directory. Your data (payments, keys,
# settings) lives in data/ and is never touched by an update.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEB_DIR="$REPO_DIR/public"
DATA_DIR="$REPO_DIR/data"
cd "$REPO_DIR"

SUDO=""
[ "$(id -u)" -ne 0 ] && SUDO="sudo"
RELOAD=0
say() { printf '\n==> %s\n' "$*"; }

# 1. Latest code -----------------------------------------------------------
say "Getting the latest from GitHub"
git -c safe.directory="$REPO_DIR" pull --ff-only

# 2. PHP with SQLite + curl (one time) -------------------------------------
if command -v apt-get >/dev/null; then
  need=()
  command -v php >/dev/null || need+=(php)
  php -m 2>/dev/null | grep -qi '^pdo_sqlite$' || need+=(php-sqlite3)
  php -m 2>/dev/null | grep -qi '^curl$' || need+=(php-curl)
  if command -v apache2ctl >/dev/null && ! apache2ctl -M 2>/dev/null | grep -qi 'php'; then
    need+=(libapache2-mod-php)
  fi
  if [ ${#need[@]} -gt 0 ]; then
    say "Installing ${need[*]} (one time)"
    $SUDO apt-get update -y
    $SUDO apt-get install -y "${need[@]}"
    RELOAD=1
  fi
fi

# 3. A private, writable folder for the database ---------------------------
mkdir -p "$DATA_DIR" 2>/dev/null || $SUDO mkdir -p "$DATA_DIR"
if id www-data >/dev/null 2>&1; then
  $SUDO chown -R www-data:www-data "$DATA_DIR"
  $SUDO chmod 770 "$DATA_DIR"
fi

# 4. Point Apache at public/ (asks first, one time) ------------------------
if [ -d /etc/apache2/sites-enabled ]; then
  confs=$(grep -lis 'cvilleglass' /etc/apache2/sites-enabled/* 2>/dev/null || true)
  [ -z "$confs" ] && confs=$(ls /etc/apache2/sites-enabled/000-default*.conf /etc/apache2/sites-enabled/default-ssl*.conf 2>/dev/null || true)
  for link in $confs; do
    conf=$(readlink -f "$link")
    current=$(awk 'tolower($1)=="documentroot" {gsub(/"/,"",$2); print $2; exit}' "$conf")
    [ -z "$current" ] || [ "$current" = "$WEB_DIR" ] && continue
    echo
    echo "Apache config $conf serves: $current"
    echo "The site now lives in:      $WEB_DIR"
    if [ -t 0 ]; then
      read -r -p "Switch Apache to the new folder? The old folder is left as it is. [y/N] " answer
    else
      answer="n"
    fi
    if [[ "$answer" == [yY]* ]]; then
      $SUDO cp "$conf" "$conf.before-cvilleglass-$(date +%Y%m%d%H%M%S)"
      $SUDO sed -i "s#^\([[:space:]]*DocumentRoot[[:space:]]\+\).*#\1$WEB_DIR#I" "$conf"
      RELOAD=1
    else
      echo "Skipped. To do it by hand, set DocumentRoot to $WEB_DIR in $conf."
    fi
  done
  case "$REPO_DIR" in
    /var/www/*) ;;
    *) echo; echo "Note: this folder isn't under /var/www, so Apache may need a <Directory $WEB_DIR> block with 'Require all granted'." ;;
  esac
fi

# 5. Check the code and reload Apache if anything changed ------------------
if command -v php >/dev/null; then
  for f in $(git ls-files '*.php'); do
    php -l "$f" >/dev/null || { echo "PHP error in $f — not reloading Apache."; exit 1; }
  done
fi
if [ "$RELOAD" -eq 1 ] && command -v apache2ctl >/dev/null; then
  say "Reloading Apache"
  $SUDO apache2ctl configtest
  $SUDO systemctl reload apache2
fi

say "Done — now at $(git log -1 --format='%h  %s  (%cr)')"
echo "    Pay page: /pay/      Admin: /admin/"
