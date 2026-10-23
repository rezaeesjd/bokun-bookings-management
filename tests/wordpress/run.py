import hashlib
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import urllib.request
import zipfile


repository = Path(__file__).resolve().parents[2]
runtime = Path(tempfile.mkdtemp(prefix="bokun-message-tests-")).resolve()
wordpress = runtime / "wordpress"
cache = Path(os.environ["BOKUN_TEST_ARCHIVE_CACHE"]) if os.environ.get("BOKUN_TEST_ARCHIVE_CACHE") else None
archives = (
    ("wordpress-test.zip", "https://wordpress.org/wordpress-7.1.3.zip", "eb58a90ed55147f90f7bd55d0d3334960a69b7e8a4cc142c8ddfea8965218227", runtime),
    ("sqlite-test.zip", "https://downloads.wordpress.org/plugin/sqlite-database-integration.3.1.0.zip", "e260e3ada78676f1c0f4e09eb9ebcb9ecce4d4b85f0c6e5a70fb9a93f5b9847f", wordpress / "wp-content/plugins"),
)


def unpack_archive(name, url, expected, destination):
    archive = runtime / name
    if cache and (cache / name).is_file():
        shutil.copyfile(cache / name, archive)
    else:
        with urllib.request.urlopen(url, timeout=90) as response:
            archive.write_bytes(response.read())
    if hashlib.sha256(archive.read_bytes()).hexdigest() != expected:
        raise RuntimeError("Pinned dependency checksum mismatch: " + name)
    destination.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(archive) as package:
        for entry in package.infolist():
            target = (destination / entry.filename).resolve()
            if not target.is_relative_to(destination.resolve()) or (entry.external_attr >> 16) & 0o170000 == 0o120000:
                raise RuntimeError("Unsafe dependency archive path")
        package.extractall(destination)


def run_php(filename, environment):
    command = [os.environ.get("BOKUN_TEST_PHP", "php")]
    command += json.loads(os.environ.get("BOKUN_TEST_PHP_ARGS", "[]"))
    command += ["-d", "max_execution_time=180", str(repository / "tests/wordpress" / filename)]
    subprocess.run(command, env=environment, check=True, cwd=repository)


try:
    for archive in archives:
        unpack_archive(*archive)
    (wordpress / ".bokun-test-runtime").write_text("disposable synthetic tests\n", encoding="utf-8")
    content = wordpress / "wp-content"
    shutil.copyfile(content / "plugins/sqlite-database-integration/db.copy", content / "db.php")
    shutil.copytree(repository, content / "plugins/bokun-bookings-management", ignore=shutil.ignore_patterns(".git", ".github", ".claude", ".env", ".env.*", "node_modules", "tests"))
    config = "<?php\n"
    values = {"DB_NAME": "bokun_messages_ci", "DB_USER": "test", "DB_PASSWORD": "", "DB_HOST": "localhost", "DB_CHARSET": "utf8", "DB_COLLATE": "", "WP_HOME": "http://127.0.0.1", "WP_SITEURL": "http://127.0.0.1"}
    for key in ("AUTH_KEY", "SECURE_AUTH_KEY", "LOGGED_IN_KEY", "NONCE_KEY", "AUTH_SALT", "SECURE_AUTH_SALT", "LOGGED_IN_SALT", "NONCE_SALT"):
        values[key] = "synthetic-disposable-" + key
    for key, value in values.items():
        config += "define(" + json.dumps(key) + ", " + json.dumps(value) + ");\n"
    config += "define('DISABLE_WP_CRON', true);\ndefine('WP_AUTO_UPDATE_CORE', false);\ndefine('AUTOMATIC_UPDATER_DISABLED', true);\ndefine('WP_DEBUG', true);\ndefine('WP_DEBUG_DISPLAY', true);\n$table_prefix = 'wp_';\nif (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }\nrequire_once ABSPATH . 'wp-settings.php';\n"
    (wordpress / "wp-config.php").write_text(config, encoding="utf-8")
    (content / "mu-plugins").mkdir(exist_ok=True)
    (content / "mu-plugins/bokun-test-safety.php").write_text("<?php\nif ('cli' !== PHP_SAPI) { exit; }\nadd_filter('pre_wp_mail', '__return_true');\n", encoding="utf-8")
    environment = os.environ.copy()
    environment.update(BOKUN_TESTS_ALLOWED="1", BOKUN_TEST_WORDPRESS_DIR=str(wordpress), BOKUN_TEST_DATABASE=str(runtime / "base.sqlite"))
    run_php("initialize.php", environment)
    for suite in ("focus.php", "imports.php", "assets.php"):
        database = runtime / (suite + ".sqlite")
        with sqlite3.connect(runtime / "base.sqlite") as source, sqlite3.connect(database) as destination:
            source.backup(destination)
        environment["BOKUN_TEST_DATABASE"] = str(database)
        run_php(suite, environment)
    print("Disposable WordPress integration suites passed; no external API or mail access.")
finally:
    if os.environ.get("BOKUN_TEST_KEEP_RUNTIME") == "1":
        print("Disposable runtime retained:", runtime)
    else:
        temporary_root = Path(tempfile.gettempdir()).resolve()
        if not runtime.is_relative_to(temporary_root) or not runtime.name.startswith("bokun-message-tests-"):
            raise RuntimeError("Refusing cleanup outside the verified disposable runtime")
        shutil.rmtree(runtime)
