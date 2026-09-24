#!/bin/bash
# Re-records docs/media/discover.gif. Needs: asciinema, agg.
#
# MUST be run from a real interactive terminal. Laravel only colours its output
# when it believes it is writing to a TTY, and that belief does not survive
# being driven from a non-interactive agent or CI shell: the recording comes out
# correct but entirely grey, which looks broken beside the other two GIFs.
#
# The GIF and the README's text block have to come from the SAME data or the
# page contradicts itself, so this points the demo application at the package's
# own fixture app for the duration of the recording and puts it back afterwards.
# The fixture is also what `vendor/bin/privacy-ci --path=tests/fixtures/demo-app`
# prints, which is where the README block is generated from.
set -euo pipefail

PKG="$(cd "$(dirname "$0")/.." && pwd)"
DEMO="${1:-$PKG/../privacy-ci-demo}"
FIXTURE="$PKG/tests/fixtures/demo-app"

[ -d "$DEMO/vendor/privacy-ci" ] || { echo "not a privacy-ci demo app: $DEMO" >&2; exit 1; }
git -C "$DEMO" diff --quiet || { echo "demo app has uncommitted changes; commit or stash first" >&2; exit 1; }

restore() {
    rm -f "$DEMO/vendor/privacy-ci/laravel"
    [ -d "$DEMO/vendor/privacy-ci/laravel.real" ] && mv "$DEMO/vendor/privacy-ci/laravel.real" "$DEMO/vendor/privacy-ci/laravel"
    git -C "$DEMO" checkout -- config/privacy.php app/Privacy/UserPrivacyPolicy.php 2>/dev/null || true
}
trap restore EXIT

# Run the branch rather than whatever Packagist installed. vendor/ is ignored by
# the demo app, so this leaves nothing behind in its history.
mv "$DEMO/vendor/privacy-ci/laravel" "$DEMO/vendor/privacy-ci/laravel.real"
ln -s "$PKG" "$DEMO/vendor/privacy-ci/laravel"

# Point it at the fixture, and use the fixture's policy: both classes are
# App\Privacy\UserPrivacyPolicy, so the app would otherwise autoload its own and
# classify the fixture's schema with the wrong rules.
php -r '
$f = $argv[1]; $c = $argv[2];
$s = file_get_contents($c);
$s = str_replace("[database_path(\"migrations\")]", "[\"$f/database/migrations\"]", $s);
$s = strtr($s, [
    "[database_path('"'"'migrations'"'"')]" => "['"'"'$f/database/migrations'"'"']",
    "[app_path('"'"'Models'"'"')]"          => "['"'"'$f/app/Models'"'"']",
    "[app_path()]"                          => "['"'"'$f/app'"'"']",
    "[config_path()]"                       => "['"'"'$f/config'"'"']",
    "base_path('"'"'composer.lock'"'"')"    => "'"'"'$f/composer.lock'"'"'",
]);
file_put_contents($c, $s);
' "$FIXTURE" "$DEMO/config/privacy.php"
cp "$FIXTURE/app/Privacy/UserPrivacyPolicy.php" "$DEMO/app/Privacy/UserPrivacyPolicy.php"

DRIVE="$(mktemp -t privacy-drive).sh"
cat > "$DRIVE" <<'DRIVER'
#!/bin/bash
prompt() { printf '\033[1;32macme/api\033[0m \033[38;5;245m$\033[0m '; }
clear
sleep 0.6
prompt
s="php artisan privacy:discover"
for ((i = 0; i < ${#s}; i++)); do printf '%s' "${s:$i:1}"; sleep 0.04; done
printf '\n'
sleep 0.5
php artisan privacy:discover
sleep 0.4
prompt
sleep 3.5
DRIVER
chmod +x "$DRIVE"

# 118 wide fits the longest line (the WARN footer); 55 tall fits the report
# without scrolling. agg's github-dark and font size 16 are what check.gif and
# verify.gif were rendered with, and the three sit together on one page.
CAST="$(mktemp -t privacy-cast).cast"
( cd "$DEMO" && asciinema rec --overwrite --cols 118 --rows 55 -c "$DRIVE" "$CAST" >/dev/null )
agg --theme github-dark --font-size 16 --cols 118 --rows 55 "$CAST" "$PKG/docs/media/discover.gif"
rm -f "$DRIVE" "$CAST"

echo "wrote docs/media/discover.gif"
echo
echo "Check it is coloured. If every row is grey, the shell driving this was not a"
echo "TTY Laravel recognised; run it again from a normal terminal window."
