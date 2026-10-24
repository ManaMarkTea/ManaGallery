"""Copy validated private material/model downloads and optional GLBs."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import secrets

ROOT=Path(__file__).resolve().parent.parent
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('source',type=Path,help='Next.js project or private data directory')
parser.add_argument('--data-dir',type=Path,default=ROOT/'storage')
parser.add_argument('--apply',action='store_true')
args=parser.parse_args()
source=args.source.resolve()
if (source/'storage').is_dir(): source=source/'storage'
private=args.data_dir.resolve()
if private==(ROOT/'public').resolve() or (ROOT/'public').resolve() in private.parents or private==source: raise SystemExit('Source and target must be separate private directories.')

def atomic(file,content):
    file.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    temporary=file.with_name('.import-'+secrets.token_hex(8))
    try:
        with temporary.open('xb') as handle:
            os.chmod(temporary,0o600)
            handle.write(content)
        os.replace(temporary,file)
    finally:
        if temporary.exists(): temporary.unlink()

def manifest(file):
    if not file.is_file() or file.is_symlink(): return {}
    data=json.loads(file.read_text(encoding='utf-8'))
    if data.get('version')!=1 or not isinstance(data.get('entries'),dict): raise ValueError('Invalid source catalogue')
    return data

counts={'materials':0,'models':0,'glb':0,'missing':0}
original_models={}
for folder,extension in [('materials','.mat'),('models','.3ds')]:
    data=manifest(source/folder/'catalogue.json')
    if not data: continue
    checked={}
    for id,meta in data['entries'].items():
        if not str(id).isascii() or not str(id).isdigit() or not 0<int(id)<1000000000 or str(int(id))!=str(id): raise ValueError('Invalid attachment identifier')
        if meta is None:
            checked[id]=None
            continue
        file=source/folder/(id+extension)
        if not file.is_file(): checked[id]=None;counts['missing']+=1;continue
        if file.is_symlink() or file.resolve().parent!=(source/folder).resolve(): raise ValueError('Attachment must be a regular source file')
        size=file.stat().st_size
        if not 0<size<=134217728 or size!=meta.get('bytes'): raise ValueError('Attachment size mismatch')
        content=file.read_bytes()
        if hashlib.sha256(content).hexdigest()!=meta.get('sha256'): raise ValueError('Attachment integrity mismatch')
        checked[id]={'name':str(meta['name']),'bytes':size,'sha256':meta['sha256']}
        counts[folder]+=1
        if args.apply: atomic(private/'attachments'/folder/file.name,content)
    if folder=='models': original_models=checked
    if args.apply: atomic(private/'attachments'/folder/'catalogue.json',json.dumps({'version':1,'entries':checked}).encode())
preview_file=source/'models/previews.json'
preview_dir=source/'models'
if not preview_file.exists(): preview_file=source/'model-previews/catalogue.json';preview_dir=source/'model-previews'
data=manifest(preview_file)
if data and data.get('geometryOnly') is True:
    checked={}
    for id,meta in data['entries'].items():
        original=original_models.get(id)
        if not original or meta.get('sourceSha256')!=original['sha256']: continue
        file=preview_dir/(id+'.glb')
        if not file.is_file(): counts['missing']+=1;continue
        if file.is_symlink() or file.resolve().parent!=preview_dir.resolve(): raise ValueError('GLB must be a regular source file')
        if not 12<=file.stat().st_size<=67108864: raise ValueError('Invalid GLB size')
        content=file.read_bytes()
        if content[:4]!=b'glTF' or len(content)!=meta['bytes'] or hashlib.sha256(content).hexdigest()!=meta['sha256']: raise ValueError('GLB integrity mismatch')
        checked[id]={key:meta[key] for key in ['bytes','sha256','sourceSha256']}
        counts['glb']+=1
        if args.apply: atomic(private/'attachments/models'/file.name,content)
    if args.apply: atomic(private/'attachments/models/previews.json',json.dumps({'version':1,'geometryOnly':True,'entries':checked}).encode())
print(('Imported' if args.apply else 'Preview:')+' '+str(counts)+'. Source files unchanged; attachment bytes remain private.')
