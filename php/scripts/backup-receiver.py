"""Forced SSH command: write-only incoming snapshots, fixed backup trigger/status."""
import fcntl,os,shutil,signal,subprocess,sys,time
from pathlib import Path

def main():
    os.umask(0o077)
    home=Path.home();base=home/'.local/share/bryce5-backup';lib=home/'.local/lib/bryce5-backup'
    command=os.environ.get('SSH_ORIGINAL_COMMAND','')
    if command=='status':
        file=base/'status.json'
        if not file.is_file():raise SystemExit('Backup status is not ready')
        sys.stdout.buffer.write(file.read_bytes());return
    if command=='run-backup':
        os.execv('/usr/bin/python3',['python3',str(lib/'backup.py'),'backup'])
    if command.startswith('rsync --server ') and not command.startswith('rsync --server --sender '):
        with (base/'receive.lock').open('a') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX)
            if shutil.disk_usage(base).free<7*1024**3:raise SystemExit('Incoming transfer refused: VPS disk reserve')
            # rrsync validates server arguments, paths and symlinks; -wo rejects reads.
            process=subprocess.Popen(['/usr/bin/rrsync','-wo',str(base/'stage')],start_new_session=True)
            while process.poll() is None:
                if shutil.disk_usage(base).free<6*1024**3:
                    os.killpg(process.pid,signal.SIGTERM);process.wait();raise SystemExit('Incoming transfer stopped to preserve free disk space')
                time.sleep(1)
            raise SystemExit(process.returncode)
    raise SystemExit('This key permits write-only backup transfers and fixed backup/status commands only.')

if __name__=='__main__':main()
