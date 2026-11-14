"""Build a release from this checkout and deploy it into a fictional host layout."""
import json, os, subprocess, tempfile, unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BINARY', 'php')


class DeployReleaseTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='managallery-deploy-')
        root = Path(self.temp.name)
        self.app, self.public, self.tools = root / 'app', root / 'public_html', root / 'native-backup-code'
        for directory in ['src', 'templates', 'public/assets', 'storage']:
            (self.app / directory).mkdir(parents=True)
        (self.public / 'assets').mkdir(parents=True)
        (self.tools / 'scripts').mkdir(parents=True)
        (self.tools / 'src').mkdir()
        (self.app / 'public/index.php').write_text('<?php // old fictional front controller\n')
        (self.app / 'src/auth.php').write_text('<?php // hand-edited on the server\n')
        (self.app / 'storage/php-gallery.sqlite').write_bytes(b'fictional database')
        (self.app / 'config.local.php').write_text('<?php return [];\n')
        (self.tools / 'scripts/snapshot.php').write_text('<?php // old backup tool\n')
        self.release = root / 'releases/github-1'
        self.run_php(ROOT / 'scripts/build-release.php', self.release, 'abc123')

    def tearDown(self):
        self.temp.cleanup()

    def run_php(self, *args, success=True):
        result = subprocess.run([PHP, *map(str, args)], capture_output=True, text=True)
        self.assertEqual(result.returncode == 0, success, result.stdout + result.stderr)
        return result.stdout + result.stderr

    def deploy(self, *extra, success=True):
        return self.run_php(self.release / 'deploy-shared-host.php', self.release, self.app, self.public, *extra, success=success)

    def test_release_replaces_code_and_backup_tools_only(self):
        manifest = json.loads((self.release / 'manifest.json').read_text())
        self.assertEqual(manifest['commit'], 'abc123')
        self.assertTrue(all(entry['replace'] == 'any' for entry in manifest['files']))
        self.assertIn('Updated', self.deploy(self.tools))
        for relative in ['src/auth.php', 'public/index.php', 'templates/layout.php']:
            self.assertEqual((self.app / relative).read_bytes(), (ROOT / relative).read_bytes())
        self.assertEqual((self.public / 'assets/site.css').read_bytes(), (ROOT / 'public/assets/site.css').read_bytes())
        self.assertEqual((self.tools / 'scripts/snapshot.php').read_bytes(), (ROOT / 'scripts/snapshot.php').read_bytes())
        self.assertEqual((self.tools / 'src/backups.php').read_bytes(), (ROOT / 'src/backups.php').read_bytes())
        self.assertEqual((self.app / 'storage/php-gallery.sqlite').read_bytes(), b'fictional database')
        self.assertEqual((self.app / 'config.local.php').read_text(), '<?php return [];\n')
        self.assertFalse((self.app / 'scripts').exists())
        previous = json.loads((self.release / 'previous-code/targets.json').read_text())
        self.assertIn(str(self.app / 'src/auth.php'), previous)
        self.assertIn('Code already current', self.deploy(self.tools))

    def test_tools_skipped_without_directory(self):
        self.deploy()
        self.assertEqual((self.tools / 'scripts/snapshot.php').read_text(), '<?php // old backup tool\n')

    def test_disallowed_path_rejected(self):
        manifest = json.loads((self.release / 'manifest.json').read_text())
        manifest['files'].append({'path': 'config.local.php', 'after': '0' * 64, 'replace': 'any'})
        (self.release / 'manifest.json').write_text(json.dumps(manifest))
        self.assertIn('Disallowed update path', self.deploy(self.tools, success=False))
        self.assertEqual((self.app / 'src/auth.php').read_text(), '<?php // hand-edited on the server\n')

    def test_reviewed_manifest_still_requires_matching_original(self):
        manifest = json.loads((self.release / 'manifest.json').read_text())
        for entry in manifest['files']:
            del entry['replace']
            entry['beforeNormalized'] = '0' * 64
        (self.release / 'manifest.json').write_text(json.dumps(manifest))
        self.assertIn('Target changed since reviewed source', self.deploy(success=False))


if __name__ == '__main__':
    unittest.main()
