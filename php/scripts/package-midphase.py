"""Create a private first-deployment snapshot; never overwrite an installed database."""
import hashlib,json,pathlib,sqlite3,sys,zipfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
if not (ROOT/'vendor/autoload.php').is_file():raise SystemExit('Run composer install --no-dev --no-plugins --no-scripts before preparing the installation package.')
NEXT=ROOT.parent/'nextjs' if (ROOT.parent/'nextjs').is_dir() else ROOT.parent/'RenderGallery'
sys.path.insert(0,str(ROOT/'scripts'))
from legacy_sql import insert_rows
STAGE=ROOT/'storage/midphase-stage'
STAGE.mkdir(parents=True,exist_ok=True)
snapshot=STAGE/'php-gallery.sqlite'
source=sqlite3.connect('file:'+str((ROOT/'storage/php-gallery.sqlite').resolve()).replace('\\','/')+'?mode=ro',uri=True)
target=sqlite3.connect(snapshot)
source.backup(target);source.close()
target.execute('DELETE FROM attempts')
target.execute('DELETE FROM artwork_viewers')
target.execute("INSERT OR REPLACE INTO settings(key,value) VALUES('registrationOpen','false')")
target.commit()
assert target.execute('PRAGMA integrity_check').fetchone()[0]=='ok'
target.close()
catalogue=json.loads((ROOT/'storage/gallery.json').read_text(encoding='utf-8'))
sql=(NEXT/'legacy/bryce5/data/database/backup202610220623.sql').read_text(encoding='latin-1')
rows={int(r['image_id']):r for r in insert_rows(sql,'legacy_images')}
manifest=[]
extra_file=ROOT/'storage/media-imports.json'
extra=json.loads(extra_file.read_text(encoding='utf-8')) if extra_file.is_file() else {}
for item in catalogue['items']:
    row=rows.get(int(item['id']));cat=str(int(row['cat_id'])) if row else None
    for key in ['image','fullImage']:
        relative=(item.get(key) or '').lstrip('/')
        if not relative:continue
        expected=NEXT/'public'/relative
        if relative in extra:
            entry=extra[relative]
            path=pathlib.PurePosixPath(entry['source'])
            if len(path.parts)!=4 or path.parts[0]!='data' or path.parts[1] not in ('media','thumbnails') or not path.parts[2].isdigit() or '..' in path.parts or '\\' in entry['source']:raise SystemExit('Invalid incremental media source.')
            if entry['target']!=relative or entry['bytes']!=expected.stat().st_size or entry['sha256']!=hashlib.sha256(expected.read_bytes()).hexdigest():raise SystemExit('Incremental media changed; review required.')
            manifest.append(entry);continue
        if row is None:raise SystemExit('Missing verified incremental media mapping for artwork '+str(item['id']))
        candidates=[('media',row['image_media_file'])] if key=='fullImage' else [('thumbnails',row['image_thumb_file']),('media',row['image_media_file']),('thumbnails',row['image_media_file'])]
        matched=None
        for folder,name in candidates:
            if not name or pathlib.Path(name).name!=name:continue
            candidate=NEXT/'legacy/bryce5/data'/folder/cat/name
            if candidate.is_file() and candidate.stat().st_size==expected.stat().st_size and hashlib.sha256(candidate.read_bytes()).digest()==hashlib.sha256(expected.read_bytes()).digest():
                matched=candidate.relative_to(NEXT/'legacy/bryce5').as_posix();break
        if not matched:raise SystemExit('No reviewed source image for artwork '+str(item['id']))
        manifest.append({'source':matched,'target':relative,'bytes':expected.stat().st_size,'sha256':hashlib.sha256(expected.read_bytes()).hexdigest()})
(STAGE/'media-manifest.json').write_text(json.dumps({'version':1,'files':manifest}),encoding='utf-8')
archive=STAGE/'rendergallery-php-first-install.zip'
with zipfile.ZipFile(archive,'w',zipfile.ZIP_DEFLATED) as z:
    for folder in ['src','templates','public','vendor']:
        for file in (ROOT/folder).rglob('*'):
            if file.is_file():z.write(file,file.relative_to(ROOT).as_posix())
    z.write(ROOT/'config.example.php','config.example.php')
    for name in ['composer.json','composer.lock']:z.write(ROOT/name,name)
    z.write(snapshot,'storage/php-gallery.sqlite')
    z.write(ROOT/'storage/gallery.json','storage/gallery.json')
    z.write(STAGE/'media-manifest.json','media-manifest.json')
    z.write(ROOT/'scripts/install-midphase.php','install-midphase.php')
    for folder in ['uploads','attachments']:
        for file in (ROOT/'storage'/folder).rglob('*'):
            if file.is_file() and not file.is_symlink():z.write(file,file.relative_to(ROOT).as_posix())
print('Private initial snapshot:',archive)
print('Archive media files to copy on-server:',len(manifest))
print('Package bytes:',archive.stat().st_size)
print('No sessions, development tools or source Next.js database included. Registration starts closed.')
