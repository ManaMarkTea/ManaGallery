"""Import public comment content/moderation, never IPs, emails or credentials."""
import argparse
from datetime import datetime,timezone
from pathlib import Path
import secrets
import sqlite3

ROOT=Path(__file__).resolve().parent.parent
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('source',type=Path)
parser.add_argument('--data-dir',type=Path,default=ROOT/'storage')
parser.add_argument('--apply',action='store_true')
args=parser.parse_args()
source=args.source/'storage/gallery.sqlite' if args.source.is_dir() else args.source
private=args.data_dir.resolve()
if private==(ROOT/'public').resolve() or (ROOT/'public').resolve() in private.parents: raise SystemExit('Comment data must be private.')
with sqlite3.connect(source.resolve().as_uri()+'?mode=ro',uri=True) as db:
    db.row_factory=sqlite3.Row
    db.execute('BEGIN')
    tables={r[0] for r in db.execute("SELECT name FROM sqlite_master WHERE type='table'")}
    def rows(table): return [dict(r) for r in db.execute('SELECT * FROM '+table)] if table in tables else []
    users={u['id']:u['username'] for u in rows('users')}
    legacy={r['legacy_id']:r for r in rows('legacy_accounts')}
    held={r['comment_id']:r['reason'] for r in rows('comment_moderation')}
    comments=[]
    for archived,table in [(False,'comments'),(True,'legacy_comments')]:
        for r in rows(table):
            id=-r['legacy_id'] if archived else r['id']
            old=legacy.get(r.get('legacy_user_id'),{})
            uid=old.get('user_id') if archived else r['user_id']
            identity='next-local:'+str(uid) if uid is not None else ('next-legacy:'+str(old['legacy_id']) if old else None)
            username=users.get(uid) or r.get('username') or old.get('username') or 'Former member'
            created=int(datetime.fromisoformat(r['created'].replace('Z','+00:00')).replace(tzinfo=timezone.utc).timestamp())
            comments.append((r['artwork_id'],identity,username,r['body'].replace('\0',''),created,held.get(id),int(archived),'next-comment:'+str(id),r.get('deleted',0)))
    db.rollback()
print(str(len(comments))+' source comments found. No private contact, address or credential fields selected.')
if args.apply:
    with sqlite3.connect(private/'php-gallery.sqlite',timeout=5) as target:
        backups=private/'backups';backups.mkdir(mode=0o700,exist_ok=True)
        file=backups/('before-comment-import-'+secrets.token_hex(8)+'.sqlite')
        with sqlite3.connect(file) as snapshot: target.backup(snapshot)
        file.chmod(0o600)
        target.execute('BEGIN IMMEDIATE')
        target.executemany('INSERT OR IGNORE INTO comments(artwork_id,import_identity,username,body,created,moderation_reason,archived,source_key,deleted) VALUES (?,?,?,?,?,?,?,?,?)',comments)
    print('Comments imported; PHP moderation and deletion tombstones preserved on re-import.')
else: print('Preview only. Add --apply to import.')
