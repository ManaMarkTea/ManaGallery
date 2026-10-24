"""Read a consistent Next.js SQLite snapshot into private PHP migration tables.

No plaintext passwords, sessions, passkeys, IP data, or source database copies
are exported. Matching names alone never establish ownership.
"""
import argparse
from datetime import datetime, timezone
import os
from pathlib import Path
import re
import secrets
import shutil
import sqlite3
import subprocess

ROOT = Path(__file__).resolve().parent.parent
ASCII_LOWER = str.maketrans("ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")


def name_key(name):
    return name.strip().translate(ASCII_LOWER)


def timestamp(value, milliseconds=False):
    if value is None:
        return None
    if isinstance(value, (int, float)):
        return int(value / 1000 if milliseconds else value)
    try:
        return int(datetime.fromisoformat(str(value).replace("Z", "+00:00")).replace(tzinfo=timezone.utc).timestamp())
    except (ValueError, OverflowError):
        return None


def original_trusted_users(file):
    if file is None:
        return set()
    from legacy_sql import insert_rows, safe_number
    sql = file.read_text(encoding="latin-1")
    users = {safe_number(r.get("user_id")): safe_number(r.get("user_level")) for r in insert_rows(sql, "legacy_users")}
    categories = {safe_number(r.get("cat_id")): r for r in insert_rows(sql, "legacy_categories")}
    now = int(datetime.now(timezone.utc).timestamp())
    access = {}
    for row in insert_rows(sql, "legacy_groupaccess"):
        if safe_number(row.get("auth_directupload")) == 1:
            access.setdefault(safe_number(row.get("group_id")), set()).add(safe_number(row.get("cat_id")))
    trusted = {uid for uid, level in users.items() if level == 9}
    for uid, level in users.items():
        if level == 2 and any(safe_number(c.get("auth_directupload")) in (0, 2) for c in categories.values()):
            trusted.add(uid)
    for row in insert_rows(sql, "legacy_groupmatch"):
        uid = safe_number(row.get("user_id"))
        start, end = safe_number(row.get("groupmatch_startdate")), safe_number(row.get("groupmatch_enddate"))
        if users.get(uid) not in (2, 9) or start > now or (end and end <= now):
            continue
        if any(safe_number(categories.get(cat, {}).get("auth_directupload")) == 3 for cat in access.get(safe_number(row.get("group_id")), set())):
            trusted.add(uid)
    return trusted


def read_source(file, legacy_dump=None):
    trusted = original_trusted_users(legacy_dump)
    db = sqlite3.connect(file.resolve().as_uri() + "?mode=ro", uri=True)
    db.row_factory = sqlite3.Row
    db.execute("BEGIN")
    try:
        tables = {row[0] for row in db.execute("SELECT name FROM sqlite_master WHERE type='table'")}
        if "users" not in tables:
            raise ValueError("Source is not a Next.js gallery database.")
        def rows(table):
            return [dict(r) for r in db.execute('SELECT * FROM "' + table + '"')] if table in tables else []
        users = rows("users")
        legacy = rows("legacy_accounts")
        policy = {r["account_key"]: r for r in rows("account_policy")}
        activity = {r["user_id"]: r for r in rows("account_activity")}
        dates = {r["legacy_id"]: r for r in rows("legacy_account_dates")}
        linked = {r["user_id"]: r for r in legacy if r.get("user_id") is not None}
        accounts, names, identities = [], {}, {}
        def add_name(name, identity):
            key = name_key(name)
            if not key or (key in names and names[key] != identity):
                raise ValueError("Source has an ambiguous account-name collision. No automatic linking is safe.")
            names[key] = identity
        def effective(local, old, field, default):
            for item in (local, old):
                if item.get(field) is not None:
                    return item[field]
            return default
        for user in users:
            identity = "next-local:" + str(user["id"])
            old = linked.get(user["id"], {})
            lp = policy.get("legacy:" + str(old.get("legacy_id")), {})
            p = policy.get("local:" + str(user["id"]), {})
            stored = user["password"]
            kind = "next-scrypt" if re.fullmatch(r"[a-fA-F0-9]{32}:[a-fA-F0-9]{128}", stored or "") else "unavailable"
            a = activity.get(user["id"], {})
            created = timestamp(user.get("created")) or timestamp(dates.get(old.get("legacy_id"), {}).get("joined"), True) or int(datetime.now(timezone.utc).timestamp())
            accounts.append((identity, user["username"], name_key(user["username"]), stored if kind != "unavailable" else None, kind,
                effective(p, lp, "role", "admin" if old.get("is_admin") else "member"),
                int(bool(effective(p, lp, "disabled", int(not old.get("enabled", 1))))),
                int(bool(effective(p, lp, "auto_approve", int(effective(p, lp, "role", "admin" if old.get("is_admin") else "member") == "admin" or old.get("legacy_id") in trusted)))), created,
                timestamp(a.get("last_login"), True), timestamp(a.get("last_seen"), True), a.get("login_count", 0)))
            add_name(user["username"], identity)
            if old:
                identities[old["legacy_id"]] = identity
                add_name(old["username"], identity)
        for old in legacy:
            if old.get("user_id") is not None:
                if old["legacy_id"] not in identities:
                    raise ValueError("Source contains an invalid legacy account link.")
                continue
            identity = "next-legacy:" + str(old["legacy_id"])
            p = policy.get("legacy:" + str(old["legacy_id"]), {})
            stored = old.get("password")
            kind = "bryce-md5" if re.fullmatch(r"(?:[^:\r\n]{9}:)?[a-fA-F0-9]{32}", stored or "") else "unavailable"
            created = timestamp(dates.get(old["legacy_id"], {}).get("joined"), True) or int(datetime.now(timezone.utc).timestamp())
            accounts.append((identity, old["username"], name_key(old["username"]), stored if kind != "unavailable" else None, kind,
                p.get("role") or ("admin" if old.get("is_admin") else "member"),
                int(bool(p["disabled"] if p.get("disabled") is not None else not old["enabled"])),
                int(bool(p["auto_approve"] if p.get("auto_approve") is not None else ((p.get("role") or ("admin" if old.get("is_admin") else "member")) == "admin" or old["legacy_id"] in trusted))), created, None, None, 0))
            identities[old["legacy_id"]] = identity
            add_name(old["username"], identity)
        ownership = [(a["artwork_id"], identities[a["legacy_user_id"]]) for a in rows("legacy_artwork") if a["legacy_user_id"] in identities and 0 < a["artwork_id"] < 1000000000]
        software = {r["artwork_id"]: r["software"] for r in rows("artwork_software")}
        metadata = [(r["artwork_id"], r["title"], r["description"], r["category_id"], software.get(r["artwork_id"], "bryce"), r.get("tags", ""), int(bool(r.get("hidden")))) for r in rows("artwork_metadata") if 0 < r["artwork_id"] < 1000000000]
        favorites = [("next-local:"+str(r["user_id"]), r["artwork_id"]) for r in rows("favorites") if r["user_id"] in {u["id"] for u in users} and 0 < r["artwork_id"] < 1000000000]
        return accounts, names, ownership, metadata, favorites
    finally:
        db.rollback()
        db.close()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", type=Path, help="Next.js project directory or private gallery.sqlite")
    parser.add_argument("--data-dir", type=Path, default=ROOT / "storage")
    parser.add_argument("--php", default=str(ROOT / ".tools/php/php.exe") if (ROOT / ".tools/php/php.exe").is_file() else shutil.which("php"))
    parser.add_argument("--legacy-dump", type=Path, help="Original private SQL export, used only to recover direct-upload group permissions")
    parser.add_argument("--apply", action="store_true", help="Default is a preview; apply creates a private target backup first")
    args = parser.parse_args()
    source = args.source / "storage/gallery.sqlite" if args.source.is_dir() else args.source
    target_dir = args.data_dir.resolve()
    target_file = target_dir / "php-gallery.sqlite"
    if source.resolve() == target_file or (ROOT / "public").resolve() in target_dir.parents or target_dir == (ROOT / "public").resolve():
        parser.error("Source and target must be separate; target storage must be private.")
    accounts, names, ownership, metadata, favorites = read_source(source, args.legacy_dump)
    print(f"Ready: {len(accounts)} source accounts, {len(ownership)} verified ownership records, {len(metadata)} archive metadata overrides.")
    if not args.apply:
        print("Preview only. Use --apply to import into the separate PHP database.")
        return
    if not args.php:
        parser.error("Provide a PHP CLI with PDO SQLite using --php.")
    env = dict(os.environ, RENDERGALLERY_PHP_DATA_DIR=str(target_dir))
    result = subprocess.run([args.php, "-r", "require $argv[1].'/src/gallery.php'; require $argv[1].'/src/database.php'; database();", str(ROOT)], env=env, capture_output=True, text=True)
    if result.returncode:
        raise ValueError("PHP database initialization failed. Check PHP SQLite support; source was not modified.")
    target = sqlite3.connect(target_file, timeout=5)
    target.row_factory = sqlite3.Row
    target.execute("PRAGMA foreign_keys=ON")
    try:
        backups = target_dir / "backups"
        backups.mkdir(mode=0o700, exist_ok=True)
        backup = backups / ("before-account-import-" + datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S") + "-" + secrets.token_hex(3) + ".sqlite")
        with sqlite3.connect(backup) as snapshot:
            target.backup(snapshot)
        backup.chmod(0o600)
        target.execute("BEGIN IMMEDIATE")
        for original in accounts:
            a = list(original)
            override = target.execute("SELECT auto_approve FROM publishing_overrides WHERE identity=?", (a[0],)).fetchone()
            if override is not None:
                a[7] = override["auto_approve"]
            existing = target.execute("SELECT * FROM imported_accounts WHERE identity=?", (a[0],)).fetchone()
            user = target.execute("SELECT id FROM users WHERE username_key=?", (a[2],)).fetchone()
            if user and (not existing or existing["local_user_id"] != user["id"]):
                raise ValueError("An unrelated PHP account has a source username. No records were linked; resolve the name collision first.")
            if existing and existing["username_key"] != a[2]:
                raise ValueError("A source identity changed its canonical name. Review the mapping before importing.")
            if existing and existing["local_user_id"]:
                if a[6]:
                    target.execute("UPDATE users SET disabled=1 WHERE id=?", (existing["local_user_id"],))
                # Repair old missing trust defaults, unless a PHP admin explicitly
                # set the policy. Never restore old hashes or reset PHP passwords.
                target.execute("UPDATE users SET auto_approve=? WHERE id=?", (a[7], existing["local_user_id"]))
                target.execute("UPDATE imported_accounts SET auto_approve=? WHERE identity=?", (a[7], a[0]))
                continue
            target.execute("INSERT INTO imported_accounts(identity,username,username_key,password,password_kind,role,disabled,auto_approve,created,last_login,last_seen,login_count) VALUES (?,?,?,?,?,?,?,?,?,?,?,?) ON CONFLICT(identity) DO UPDATE SET username=excluded.username,username_key=excluded.username_key,password=excluded.password,password_kind=excluded.password_kind,role=excluded.role,disabled=excluded.disabled,auto_approve=excluded.auto_approve,created=excluded.created,last_login=excluded.last_login,last_seen=excluded.last_seen,login_count=excluded.login_count", a)
        # Validate every alias too, including sources with a renamed local user.
        for key, identity in names.items():
            imported = target.execute("SELECT local_user_id FROM imported_accounts WHERE identity=?", (identity,)).fetchone()
            user = target.execute("SELECT id FROM users WHERE username_key=?", (key,)).fetchone()
            if user and imported["local_user_id"] != user["id"]:
                raise ValueError("A prototype account conflicts with a source alias. No records were linked.")
            old = target.execute("SELECT identity FROM imported_names WHERE username_key=?", (key,)).fetchone()
            if old and old["identity"] != identity:
                raise ValueError("Source identity collision. No records were linked.")
            target.execute("INSERT OR IGNORE INTO imported_names(username_key,identity) VALUES (?,?)", (key, identity))
        for record in ownership:
            old = target.execute("SELECT identity FROM imported_artwork WHERE artwork_id=?", (record[0],)).fetchone()
            if old and old["identity"] != record[1]:
                raise ValueError("Source artwork ownership changed. Review the mapping before importing.")
            target.execute("INSERT OR IGNORE INTO imported_artwork(artwork_id,identity) VALUES (?,?)", record)
        for record in metadata:
            target.execute("INSERT OR IGNORE INTO artwork_overrides(artwork_id,title,description,category_id,software,tags,hidden) VALUES (?,?,?,?,?,?,?)", record)
        for identity, artwork in favorites:
            target.execute("INSERT OR IGNORE INTO imported_favorites(identity,artwork_id) VALUES (?,?)", (identity, artwork))
            imported = target.execute("SELECT local_user_id FROM imported_accounts WHERE identity=?", (identity,)).fetchone()
            record = target.execute("SELECT applied FROM imported_favorites WHERE identity=? AND artwork_id=?", (identity, artwork)).fetchone()
            if imported["local_user_id"] and not record["applied"]:
                target.execute("INSERT OR IGNORE INTO favorites(user_id,artwork_id,created) VALUES (?,?,?)", (imported["local_user_id"], artwork, int(datetime.now(timezone.utc).timestamp())))
                target.execute("UPDATE imported_favorites SET applied=1 WHERE identity=? AND artwork_id=?", (identity, artwork))
        target.commit()
        print("Import complete. Existing PHP passwords, migrated links and local edits were preserved. Source database was read only.")
    except Exception:
        target.rollback()
        raise
    finally:
        target.close()


if __name__ == "__main__":
    try:
        main()
    except (ValueError, sqlite3.Error, OSError) as error:
        raise SystemExit(str(error)) from None
