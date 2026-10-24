"""Validate a restored PHP gallery tree without executing restored PHP code."""
import hashlib,json,sqlite3
from contextlib import closing
from pathlib import Path,PurePosixPath

def verify(stage):
    stage=Path(stage).resolve(strict=True)
    manifest=json.loads((stage/'manifest.json').read_text())
    if manifest['version']!=1 or not manifest['files']:raise ValueError('Invalid recovery manifest')
    for relative,entry in manifest['files'].items():
        parts=PurePosixPath(relative)
        if parts.is_absolute() or '..' in parts.parts or '\\' in relative:raise ValueError('Unsafe recovery path')
        file=stage.joinpath(*parts.parts)
        if file.is_symlink() or not file.is_file() or not file.resolve().is_relative_to(stage):raise ValueError('Missing recovery file')
        with file.open('rb') as handle:digest=hashlib.file_digest(handle,'sha256').hexdigest()
        if file.stat().st_size!=entry['bytes'] or digest!=entry['sha256']:raise ValueError('Recovery checksum mismatch: '+relative)
    with closing(sqlite3.connect((stage/'storage/php-gallery.sqlite').as_uri()+'?mode=ro',uri=True)) as db:
        if db.execute('PRAGMA integrity_check').fetchone()[0]!='ok':raise ValueError('Database integrity failed')
        if db.execute('PRAGMA foreign_key_check').fetchone():raise ValueError('Database foreign key check failed')
        for row in db.execute('SELECT filename,preview FROM uploads'):
            for filename in row:
                if 'storage/uploads/'+filename not in manifest['files']:raise ValueError('Missing uploaded image')
    catalogue=json.loads((stage/'storage/gallery.json').read_text())
    for item in catalogue['items']:
        for field in ['image','fullImage']:
            relative=item.get(field,'')
            if relative and 'storage/archive'+relative not in manifest['files']:raise ValueError('Missing archived image')
    for kind in ['materials','models']:
        file=stage/'storage/attachments'/kind/'catalogue.json'
        if not file.is_file():continue
        data=json.loads(file.read_text())
        extension='mat' if kind=='materials' else '3ds'
        for ident,entry in data['entries'].items():
            if entry:
                relative=f'storage/attachments/{kind}/{ident}.{extension}'
                if manifest['files'].get(relative,{}).get('sha256')!=entry['sha256']:raise ValueError('Attachment integrity failed')
    return len(manifest['files'])

if __name__=='__main__':
    import sys
    print('Verified',verify(sys.argv[1]),'restored files, database, uploads, archive and attachments.')
