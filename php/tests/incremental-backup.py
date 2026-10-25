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
