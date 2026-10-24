"""Copy public archive metadata only; never reads accounts, secrets, or SQLite."""
import argparse
import json
from pathlib import Path
import shutil

parser = argparse.ArgumentParser()
parser.add_argument("source", type=Path, help="The adjacent Next.js project")
parser.add_argument("--copy-media", action="store_true", help="Copy the public gallery/full image archive for standalone hosting")
args = parser.parse_args()
root = Path(__file__).resolve().parent.parent
source = args.source.resolve()
public = source / "public"
raw = json.loads((public / "data/gallery.json").read_text(encoding="utf-8"))
fields = ("id", "title", "description", "author", "categoryId", "category", "date", "views", "rating", "image", "fullImage", "software", "tags", "hidden", "pending")
items = [{key: item[key] for key in fields if key in item} for item in raw["items"] if not item.get("hidden") and not item.get("pending")]
categories = [{"id": c["id"], "name": c["name"]} for c in raw["categories"]]
output = root / "storage/gallery.json"
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(json.dumps({"items": items, "categories": categories}, ensure_ascii=False), encoding="utf-8")
media = public
if args.copy_media:
    media = root / "storage/archive"
    for name in ("gallery", "full"):
        print("Copying " + name + " images…", flush=True)
        shutil.copytree(public / name, media / name, dirs_exist_ok=True)
config = root / "config.local.php"
if config.exists():
    print("Existing config.local.php preserved. Check its media_root before running.")
else:
    def quote(value):
        return "'" + str(value).replace("\\", "/").replace("'", "\\'") + "'"
    config.write_text("<?php\nreturn ['media_root' => " + quote(media.resolve()) + "];\n", encoding="utf-8")
print("Imported " + str(len(items)) + " public archive entries. No private accounts or live database were read.")
print("This snapshot does not include live moderation overrides or newer uploads. Keep the prototype private.")
