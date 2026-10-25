"""Rootless off-server backups for a shared-hosted gallery; credentials stay private."""
import configparser,fcntl,json,os,shutil,subprocess,sys,tempfile,time
from datetime import datetime,timedelta,timezone
from pathlib import Path

HOME=Path.home()
BASE=Path(os.environ.get('MANAGALLERY_BACKUP_BASE',HOME/'.local/share/managallery-backup'))
CONFIG=Path(os.environ.get('MANAGALLERY_BACKUP_CONFIG',HOME/'.config/managallery-backup'))
LIB=Path(os.environ.get('MANAGALLERY_BACKUP_LIB',HOME/'.local/lib/managallery-backup'))
STAGE=BASE/'stage'
REPOSITORY=BASE/'repository'
PASSWORD=Path(os.environ.get('MANAGALLERY_BACKUP_PASSWORD_FILE',CONFIG/'recovery-password'))
REMOTE=os.environ.get('MANAGALLERY_DRIVE_REMOTE','gallery-drive')
CLOUD='rclone:'+REMOTE+':'
TAG='managallery'
STATE=BASE/'status.json'
LOGS={}

def timestamp():return int(time.time())

def next_run(kind):
    now=datetime.now(timezone.utc)
    if kind in ('backup','cloud'):
        target=now.replace(hour=10,minute=35,second=0,microsecond=0)
        if target<=now:target+=timedelta(days=1)
    elif kind=='maintenance':
        target=now.replace(hour=11,minute=35,second=0,microsecond=0)
        while target<=now or target.weekday()!=5:target+=timedelta(days=1)
    else:
        target=now.replace(day=15,hour=12,minute=35,second=0,microsecond=0)
        if target<=now:
            target=target.replace(year=target.year+1,month=1) if target.month==12 else target.replace(month=target.month+1)
    return int(target.timestamp())

def load():
    data=json.loads(STATE.read_text()) if STATE.exists() else {'version':1,'jobs':{}}
    for kind in ('backup','restore','cloud','maintenance'):
        data['jobs'].setdefault(kind,{'status':'pending','log':''})
        data['jobs'][kind]['nextRun']=next_run(kind)
    return data

def secrets():
    # Never log OAuth credentials, including refreshes written by rclone.
    values=[]
    file=CONFIG/'rclone.conf'
    if file.exists():
        parser=configparser.ConfigParser(interpolation=None);parser.read(file)
        for section in parser.sections():
            try:
                token=json.loads(parser.get(section,'token',fallback='{}'))
                values.extend(v for v in token.values() if isinstance(v,str) and len(v)>20)
            except (ValueError,configparser.Error):pass
    return values

def redact(text):
    for value in secrets():text=text.replace(value,'[redacted]')
    return text

def env():
    return {**os.environ,'RESTIC_PASSWORD_FILE':str(PASSWORD),'RESTIC_CACHE_DIR':str(BASE/'cache'),
            'RCLONE_CONFIG':str(CONFIG/'rclone.conf'),'PATH':str(HOME/'.local/bin')+':/usr/bin:/bin'}

def run(arguments,kind,environment=None,allow_failure=False):
    label=datetime.now(timezone.utc).strftime('[%H:%M:%S UTC] ')+arguments[0].split('/')[-1]+' '+next((s for s in arguments[1:] if s in ('backup','copy','restore','check','init','forget','snapshot')),'working')
    print(label,flush=True);LOGS[kind]=(LOGS.get(kind,'')+'\n'+label)[-9000:]
    process=subprocess.Popen([str(a) for a in arguments],stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True,env=environment or env(),bufsize=1)
    lines=[]
    for line in process.stdout:
        clean=redact(line.rstrip());print(clean,flush=True);lines.append(clean)
        LOGS[kind]=(LOGS.get(kind,'')+'\n'+clean)[-9000:]
    result=process.wait()
    if result and not allow_failure:raise RuntimeError('Backup operation failed (exit '+str(result)+'); see the job log.')
    return result

def publish(data):
    data['checked']=timestamp()
    for kind,job in data['jobs'].items():
        job['nextRun']=next_run(kind)
        if kind in LOGS:job['log']=LOGS[kind][-9000:]
    temporary=STATE.with_suffix('.next');temporary.write_text(json.dumps(data));temporary.chmod(0o600);temporary.replace(STATE)
    message=json.dumps(data,ensure_ascii=False).encode()
    if len(message)>60000:raise RuntimeError('Backup status exceeds publication limit')
    # The hosting server pulls only this sanitized status using its restricted key.

def job(data,kind,work):
    record=data['jobs'][kind];record.update(status='running',lastRun=timestamp());LOGS[kind]='Starting '+kind;publish(data)
    try:
        work();record.update(status='success',lastSuccess=timestamp())
    except Exception as error:
        record['status']='failed';LOGS[kind]=LOGS.get(kind,'')+'\n'+redact(str(error));publish(data);raise
    publish(data)

def verify(path,kind):run(['/usr/bin/python3',LIB/'verify-backup.py',path],kind)

def check_stage():
    if STAGE.is_symlink() or not STAGE.resolve().is_relative_to(BASE.resolve()):raise RuntimeError('Unsafe receive stage')
    if STAGE.exists() and (STAGE/'.managallery-backup-stage').read_text()!='ManaGallery backup stage v1\n':raise RuntimeError('Unknown receive directory')
    STAGE.mkdir(mode=0o700,exist_ok=True)
    (STAGE/'.managallery-backup-stage').write_text('ManaGallery backup stage v1\n')

def cloud_ready():
    file=CONFIG/'rclone.conf'
    if not file.exists() or not (CONFIG/'drive-ready').exists():return False
    parser=configparser.ConfigParser(interpolation=None);parser.read(file)
    if parser.get(REMOTE,'scope',fallback='')!='drive.file':raise RuntimeError('Refusing broader Google Drive scope')
    folder=parser.get(REMOTE,'root_folder_id',fallback='')
    if not folder or not folder.replace('-','').replace('_','').isalnum():raise RuntimeError('Backup folder is not pinned')
    return True

def local_backup():
    if shutil.disk_usage(BASE).free<7*1024**3:raise RuntimeError('Less than 7 GiB free on backup VPS; inspect disk usage before receiving files')
    with (BASE/'receive.lock').open('a') as incoming:
        fcntl.flock(incoming,fcntl.LOCK_EX)
        check_stage()
        manifest=json.loads((STAGE/'manifest.json').read_text())
        created=datetime.fromisoformat(manifest['created']).timestamp()
        if created<time.time()-2*3600 or created>time.time()+300:raise RuntimeError('Hosting snapshot is not fresh; refusing to report an old transfer as a new backup')
        verify(STAGE,'backup')
        if not (REPOSITORY/'config').exists():run(['/usr/bin/restic','-r',REPOSITORY,'init'],'backup')
        run(['/usr/bin/restic','-r',REPOSITORY,'backup','--ignore-ctime','--host','managallery-shared-host','--tag',TAG,STAGE],'backup')
        run(['/usr/bin/restic','-r',REPOSITORY,'check'],'backup')

def cloud_copy():
    run(['/usr/bin/restic','-r',CLOUD,'copy','--from-repo',REPOSITORY,'--from-password-file',PASSWORD,'--tag',TAG],'cloud')
    run(['/usr/bin/restic','-r',CLOUD,'check'],'cloud')

def restore(repository,kind):
    if shutil.disk_usage(BASE).free<4*1024**3:raise RuntimeError('Less than 4 GiB free for restore check')
    drill=Path(tempfile.mkdtemp(prefix='restore-check-',dir=BASE))
    try:
        run(['/usr/bin/restic','-r',repository,'restore','latest','--tag',TAG,'--target',drill],kind)
        verify(drill/STAGE.relative_to('/'),kind)
    finally:
        if drill.parent!=BASE or not drill.name.startswith('restore-check-') or drill.is_symlink():raise RuntimeError('Unsafe restore cleanup path')
        shutil.rmtree(drill)

def maintenance():
    for repository in ([REPOSITORY,CLOUD] if cloud_ready() else [REPOSITORY]):
        run(['/usr/bin/restic','-r',repository,'forget','--tag',TAG,'--keep-daily','7','--keep-weekly','4','--keep-monthly','6','--prune'],'maintenance')
        run(['/usr/bin/restic','-r',repository,'check'],'maintenance')

def main():
    os.umask(0o077)
    BASE.mkdir(mode=0o700,parents=True,exist_ok=True);CONFIG.mkdir(mode=0o700,parents=True,exist_ok=True)
    if BASE.is_symlink() or not PASSWORD.is_file():raise RuntimeError('Private backup configuration unavailable')
    mode=sys.argv[1] if len(sys.argv)==2 else ''
    if mode not in ('backup','restore','maintenance','connect-drive','cloud','status'):raise SystemExit('Expected backup, restore, maintenance, connect-drive, cloud or status')
    with (BASE/'job.lock').open('a') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        data=load()
        if mode=='backup':
            job(data,'backup',local_backup)
            if cloud_ready():job(data,'cloud',cloud_copy)
            else:
                data['jobs']['cloud'].update(status='pending',log='Google Drive authorization/initialization is not yet complete. Encrypted off-server VPS backups run independently.');publish(data)
        elif mode=='restore':
            def work():
                restore(REPOSITORY,'restore')
                if cloud_ready():restore(CLOUD,'restore')
            job(data,'restore',work)
        elif mode=='maintenance':job(data,'maintenance',maintenance)
        elif mode=='connect-drive':
            # Explicit first initialization only; never create a fallback repository.
            if not (REPOSITORY/'config').exists():raise RuntimeError('Take an encrypted VPS snapshot before initializing Google Drive')
            parser=configparser.ConfigParser(interpolation=None);parser.read(CONFIG/'rclone.conf')
            if parser.get(REMOTE,'scope',fallback='')!='drive.file' or not parser.get(REMOTE,'root_folder_id',fallback=''):raise RuntimeError('Expected app-created-files scope and pinned backup folder')
            def connect():
                probe=run(['/usr/bin/restic','-r',CLOUD,'snapshots','--json'],'cloud',allow_failure=True)
                if probe:
                    initial={**env(),'RESTIC_FROM_REPOSITORY':str(REPOSITORY),'RESTIC_FROM_PASSWORD_FILE':str(PASSWORD)}
                    run(['/usr/bin/restic','-r',CLOUD,'init','--copy-chunker-params'],'cloud',environment=initial)
                cloud_copy();restore(CLOUD,'cloud')
                (CONFIG/'drive-ready').write_text('Initialized and restore checked\n')
            job(data,'cloud',connect)
        elif mode=='cloud':
            if not cloud_ready():raise RuntimeError('Google Drive is not ready')
            job(data,'cloud',cloud_copy)
        else:publish(data)

if __name__=='__main__':
    try:main()
    except Exception as error:
        print('Backup job failed: '+redact(str(error)),file=sys.stderr);raise SystemExit(1)
