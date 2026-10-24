import hashlib,importlib.util,json,sqlite3,tempfile,unittest
from contextlib import closing
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('backup_verify',ROOT/'scripts/verify-backup.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)

class RecoveryTests(unittest.TestCase):
    def setUp(self):
        self.temporary=tempfile.TemporaryDirectory(prefix='bryce-backup-test-')
        self.root=Path(self.temporary.name)
        for directory in ['storage/uploads','storage/archive/gallery','storage/archive/full']:(self.root/directory).mkdir(parents=True,exist_ok=True)
        for relative in ['storage/uploads/fake.webp','storage/uploads/preview-fake.webp','storage/archive/gallery/1.jpg','storage/archive/full/1.jpg']:(self.root/relative).write_bytes(b'Fictional checked transport bytes')
        with closing(sqlite3.connect(self.root/'storage/php-gallery.sqlite')) as db,db:
            db.execute('CREATE TABLE uploads(filename TEXT, preview TEXT)')
            db.execute("INSERT INTO uploads VALUES ('fake.webp','preview-fake.webp')")
        (self.root/'storage/gallery.json').write_text(json.dumps({'items':[{'image':'/gallery/1.jpg','fullImage':'/full/1.jpg'}]}))
        self.refresh()
    def tearDown(self):self.temporary.cleanup()
    def refresh(self):
        self.manifest={'version':1,'files':{}}
        for file in self.root.rglob('*'):
            if file.is_file() and file.name!='manifest.json':self.manifest['files'][file.relative_to(self.root).as_posix()]={'bytes':file.stat().st_size,'sha256':hashlib.sha256(file.read_bytes()).hexdigest()}
        self.save()
    def save(self):(self.root/'manifest.json').write_text(json.dumps(self.manifest))
    def test_checked_recovery(self):self.assertEqual(module.verify(self.root),6)
    def test_corrupted_upload(self):
        (self.root/'storage/uploads/fake.webp').write_bytes(b'corrupted')
        with self.assertRaises(ValueError):module.verify(self.root)
    def test_missing_referenced_upload_even_with_valid_manifest(self):
        (self.root/'storage/uploads/preview-fake.webp').unlink();self.refresh()
        with self.assertRaisesRegex(ValueError,'Missing uploaded'):module.verify(self.root)
    def test_missing_archived_image_even_with_valid_manifest(self):
        (self.root/'storage/archive/full/1.jpg').unlink();self.refresh()
        with self.assertRaisesRegex(ValueError,'Missing archived'):module.verify(self.root)
    def test_manifest_traversal_rejected(self):
        self.manifest['files']['../outside']={'bytes':0,'sha256':'0'*64};self.save()
        with self.assertRaisesRegex(ValueError,'Unsafe recovery path'):module.verify(self.root)
    def test_corrupted_database(self):
        (self.root/'storage/php-gallery.sqlite').write_bytes(b'not sqlite');self.refresh()
        with self.assertRaises(sqlite3.DatabaseError):module.verify(self.root)

if __name__=='__main__':unittest.main()
