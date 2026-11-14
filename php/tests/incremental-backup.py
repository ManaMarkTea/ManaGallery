"""Exercise real encrypted snapshots and restores using fictional private data."""
from contextlib import closing
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')


class IncrementalBackupTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='managallery-chain-')
        self.root = Path(self.temp.name)
        self.app = self.root / 'app'
        self.base = self.root / 'backups'
        self.base.mkdir()
        (self.app / 'storage/uploads').mkdir(parents=True)
        (self.app / 'src').mkdir()
        (self.app / 'src/example.php').write_text('<?php // fictional code\n')
        (self.app / 'storage/uploads/original.webp').write_bytes(b'Fictional media' * 1000)
        (self.app / 'storage/uploads/preview.webp').write_bytes(b'Fictional preview')
        (self.app / 'storage/uploads/deleted.webp').write_bytes(b'Fictional removed file')
        with closing(sqlite3.connect(self.app / 'storage/php-gallery.sqlite')) as db, db:
            db.execute('CREATE TABLE uploads(filename TEXT, preview TEXT)')
            db.execute("INSERT INTO uploads VALUES ('original.webp','preview.webp')")
        self.password = self.root / 'password'
        self.password.write_text('Fictional-strong-test-recovery-password-0123456789')
        self.full = self.snapshot()
        (self.app / 'src/example.php').write_text('<?php // changed fictional code\n')
        (self.app / 'storage/uploads/deleted.webp').unlink()
        (self.app / 'storage/uploads/new.webp').write_bytes(b'Fictional added file')
        self.delta = self.snapshot()

    def tearDown(self):
        self.temp.cleanup()

    def command(self, script, *args, success=True):
        result = subprocess.run([PHP, str(ROOT / 'scripts' / script), *map(str, args)], capture_output=True, text=True)
        if success:
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        else:
            self.assertNotEqual(result.returncode, 0, 'Unsafe restore unexpectedly succeeded')
        return result

    def snapshot(self):
        self.command('snapshot.php', self.app, self.base, self.password, '--incremental')
        return self.base / json.loads((self.base / 'latest.json').read_text())['archive']

    def test_changed_files_only_and_complete_restore(self):
        state = json.loads((self.base / 'chain-state.json').read_text())
        self.assertIsNotNone(state['parent'])
        self.assertNotIn('storage/uploads/original.webp', state['changed'])
        self.assertIn('src/example.php', state['changed'])
        self.assertIn('storage/uploads/new.webp', state['changed'])
        self.assertEqual(state['deleted'], ['storage/uploads/deleted.webp'])
        destination = self.root / 'restore'
        self.command('restore-chain.php', destination, self.password, self.full, self.delta)
        self.assertEqual((destination / 'src/example.php').read_bytes(), (self.app / 'src/example.php').read_bytes())
        self.assertFalse((destination / 'storage/uploads/deleted.webp').exists())
        self.assertEqual((destination / 'storage/uploads/original.webp').read_bytes(), (self.app / 'storage/uploads/original.webp').read_bytes())

    def test_incomplete_and_out_of_order_chain_rejected(self):
        self.command('restore-chain.php', self.root / 'missing', self.password, self.delta, success=False)
        self.command('restore-chain.php', self.root / 'reversed', self.password, self.delta, self.full, success=False)
        self.command('restore-chain.php', self.root / 'duplicate', self.password, self.full, self.delta, self.delta, success=False)

    def test_wrong_password_and_existing_destination_rejected(self):
        wrong = self.root / 'wrong-password'
        wrong.write_text('Different-fictional-test-password-0123456789')
        self.command('restore-chain.php', self.root / 'wrong', wrong, self.full, self.delta, success=False)
        existing = self.root / 'existing'
        existing.mkdir()
        self.command('restore-chain.php', existing, self.password, self.full, self.delta, success=False)

    def test_corrupted_archive_rejected(self):
        corrupt = self.root / 'corrupt.zip'
        corrupt.write_bytes(self.delta.read_bytes()[:100])
        self.command('restore-chain.php', self.root / 'corrupt', self.password, self.full, corrupt, success=False)

    def test_manifest_path_traversal_rejected(self):
        malicious = self.root / 'malicious.zip'
        creator = self.root / 'create-malicious.php'
        creator.write_text('''<?php
$z=new ZipArchive();$z->open($argv[1],ZipArchive::CREATE);$z->setPassword(file_get_contents($argv[2]));
$m=['format'=>'ManaGallery recovery v2','id'=>'fixture','parent'=>null,'files'=>['../outside'=>['bytes'=>4,'sha256'=>hash('sha256','evil')]],'changed'=>['../outside'],'deleted'=>[]];
$z->addFromString('manifest.json',json_encode($m));$z->setEncryptionName('manifest.json',ZipArchive::EM_AES_256);
$z->addFromString('../outside','evil');$z->setEncryptionName('../outside',ZipArchive::EM_AES_256);$z->close();
''')
        subprocess.run([PHP, str(creator), str(malicious), str(self.password)], check=True, capture_output=True)
        self.command('restore-chain.php', self.root / 'malicious-restore', self.password, malicious, success=False)
        self.assertFalse((self.root / 'outside').exists())

    def test_new_full_baseline_restores_independently(self):
        self.command('snapshot.php', self.app, self.base, self.password, '--incremental', '--full')
        baseline = self.base / json.loads((self.base / 'latest.json').read_text())['archive']
        self.assertIsNone(json.loads((self.base / 'chain-state.json').read_text())['parent'])
        self.command('restore-chain.php', self.root / 'new-full', self.password, baseline)

    def test_failed_snapshot_leaves_no_staging_or_partial_archive(self):
        before = sorted(self.base.iterdir())
        (self.app / 'storage/uploads/preview.webp').unlink()
        result = self.command('snapshot.php', self.app, self.base, self.password, '--incremental', success=False)
        self.assertIn('missing a referenced upload', result.stderr + result.stdout)
        self.assertEqual(sorted(self.base.iterdir()), before)
        self.assertTrue((self.app / 'storage/uploads/original.webp').is_file())

    def test_leftovers_from_interrupted_runs_are_removed(self):
        stale = self.base / 'managallery-20261110T141540Z-a1b610.stage'
        (stale / 'storage/uploads').mkdir(parents=True)
        os.link(self.app / 'storage/uploads/original.webp', stale / 'storage/uploads/original.webp')
        (stale / 'storage/php-gallery.sqlite').write_bytes(b'Fictional database copy')
        partial = self.base / 'managallery-20261110T141540Z-a1b610.zip.partial'
        partial.write_bytes(b'Fictional unfinished archive')
        unrelated = self.base / 'keep-me.stage'
        unrelated.mkdir()
        self.snapshot()
        self.assertFalse(stale.exists())
        self.assertFalse(partial.exists())
        self.assertTrue(unrelated.is_dir())
        self.assertEqual(list(self.base.glob('*.stage')), [unrelated])
        self.assertTrue((self.app / 'storage/uploads/original.webp').is_file())

    def php(self, code):
        result = subprocess.run([PHP, '-r', "require '" + str(ROOT / 'src/backups.php') + "';" + code], capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return json.loads(result.stdout)

    def receipts(self, *archives):
        receipts = {a.name: {'id': 'drive-' + a.stem[-6:], 'sha256': 'f' * 64, 'bytes': a.stat().st_size, 'checked': int(a.stat().st_mtime) + 5} for a in archives}
        (self.base / 'drive-receipts.json').write_text(json.dumps(receipts))
        return receipts

    def archives(self):
        return {a['name']: a for a in self.php("echo json_encode(backup_archives('" + str(self.base) + "'));")}

    def test_chain_index_records_each_snapshot(self):
        index = json.loads((self.base / 'chain-index.json').read_text())
        self.assertEqual(index[self.full.stem], {'parent': None})
        self.assertEqual(index[self.delta.stem], {'parent': self.full.stem})

    def test_uploaded_archives_removed_but_full_kept_until_recovery_check(self):
        local = self.base / 'managallery-20261101T000000Z-abcdef.zip'
        local.write_bytes(b'Not yet uploaded')
        self.receipts(self.full, self.delta)
        archives = self.archives()
        self.assertFalse(archives[self.full.name]['removable'])
        self.assertIn('recovery check', archives[self.full.name]['status'])
        self.assertTrue(archives[self.delta.name]['removable'])
        self.assertFalse(archives[local.name]['removable'])
        result = self.php("echo json_encode(remove_uploaded_backup_archives('" + str(self.base) + "'));")
        self.assertEqual(result['removed'], 1)
        self.assertFalse(self.delta.exists())
        self.assertTrue(self.full.exists() and local.exists())
        (self.base / 'drive-restore-check.json').write_text(json.dumps({'status': 'success', 'backupId': self.delta.stem, 'checked': 1}))
        self.assertTrue(self.archives()[self.full.name]['removable'])
        self.php("echo json_encode(remove_uploaded_backup_archives('" + str(self.base) + "'));")
        self.assertFalse(self.full.exists())
        self.assertTrue(local.exists() and (self.base / 'chain-state.json').exists())

    def test_failed_recovery_check_keeps_full_baseline(self):
        self.receipts(self.full, self.delta)
        (self.base / 'drive-restore-check.json').write_text(json.dumps({'status': 'failed', 'backupId': self.delta.stem}))
        self.assertFalse(self.archives()[self.full.name]['removable'])

    def test_archive_changed_after_upload_is_kept(self):
        self.receipts(self.full, self.delta)
        os.utime(self.delta, (self.delta.stat().st_mtime + 60,) * 2)
        self.assertFalse(self.archives()[self.delta.name]['removable'])

    def test_unindexed_archive_kept(self):
        self.receipts(self.full, self.delta)
        (self.base / 'chain-index.json').unlink()
        self.assertFalse(any(a['removable'] for a in self.archives().values()))

    def test_recovery_chain_found_after_local_archives_removed(self):
        receipts = self.receipts(self.full, self.delta)
        self.full.unlink()
        self.delta.unlink()
        chain = self.php("echo json_encode(backup_chain('" + str(self.base) + "', '" + self.delta.stem + "', json_decode(file_get_contents('" + str(self.base / 'drive-receipts.json') + "'), true), 'unused'));")
        self.assertEqual(list(chain), [self.delta.stem, self.full.stem])
        self.assertEqual(chain[self.full.stem], receipts[self.full.name])

    def test_recovery_chain_reads_unindexed_local_archives(self):
        self.receipts(self.full, self.delta)
        (self.base / 'chain-index.json').unlink()
        password = self.password.read_text()
        chain = self.php("echo json_encode(array_keys(backup_chain('" + str(self.base) + "', '" + self.delta.stem + "', json_decode(file_get_contents('" + str(self.base / 'drive-receipts.json') + "'), true), '" + password + "')));")
        self.assertEqual(chain, [self.delta.stem, self.full.stem])

    def test_status_preserves_prior_success_when_cloud_or_restore_fails(self):
        settings = self.root / 'settings.json'
        settings.write_text(json.dumps({'appDirectory': str(self.app), 'backupDirectory': str(self.base)}))
        (self.base / 'drive-receipts.json').write_text(json.dumps({self.full.name: {'checked': 123}}))
        (self.base / 'drive-restore-check.json').write_text(json.dumps({'status': 'failed', 'checked': 456, 'lastRun': 789}))
        self.command('drive-backup-status.php', settings, '1', '0')
        status = json.loads((self.app / 'storage/backup-status.json').read_text())
        self.assertEqual(status['provider'], 'php-drive')
        self.assertEqual(status['jobs']['cloud']['status'], 'failed')
        self.assertEqual(status['jobs']['cloud']['lastSuccess'], 123)
        self.assertEqual(status['jobs']['backup']['status'], 'success')
        self.assertEqual(status['jobs']['restore']['status'], 'failed')
        self.assertEqual(status['jobs']['restore']['lastSuccess'], 456)
        self.assertEqual(status['jobs']['restore']['lastRun'], 789)


if __name__ == '__main__':
    unittest.main()
