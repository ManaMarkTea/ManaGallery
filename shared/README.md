# Shared source

These files were byte-equivalent apart from line endings in both implementations
before consolidation:

| Canonical source | Next.js copy | PHP copy |
| --- | --- | --- |
| `styles/gallery.css` | `app/globals.css` | `public/assets/site.css` |
| `moderation/comment-rules.json` | `lib/comment-rules.json` | `src/comment-rules.json` |
| `assets/icon.svg` | `app/icon.svg` | `public/assets/icon.svg` |
| `assets/placeholder.svg` | `public/test-placeholder.svg` | `public/assets/placeholder.svg` |

Paths in the last two columns are relative to their application folders.
Use `python scripts/sync-shared.py` from the repository root after editing shared
source. `--check` reports differences without writing anything. Copies keep each
build and deployment self-contained; do not replace them with filesystem links.

Application-specific styles, server code and database schemas remain in their
respective applications. They implement similar features but are not interchangeable.
