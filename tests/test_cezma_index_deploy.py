import json
import os
import tempfile
import unittest
from unittest import mock

from updater import update_catalog


class FakeFTP:
    files = {}
    listed = []

    def __init__(self, host, timeout=90):
        self.host = host
        self.timeout = timeout
        self.closed = False

    def login(self, user, password):
        return '230 logged in'

    def cwd(self, path):
        return '250 directory changed'

    def nlst(self, path):
        return list(self.listed)

    def retrbinary(self, command, callback):
        remote_path = command[5:]
        callback(self.files[remote_path])

    def quit(self):
        self.closed = True
        return '221 bye'


class CezmaIndexOnlyDeployTests(unittest.TestCase):
    def test_deploy_reads_live_shards_and_preserves_catalog_count_and_sources(self):
        rows_a = [
            {'id': 'cz-oven', 'name': 'فرن كهربائي', 'category': 'الموضة والجمال', 'country': 'مصر', 'images': ['https://img.test/oven.jpg']},
        ]
        rows_b = [
            {'id': 'cz-shoe', 'name': 'حذاء جديد', 'category': 'الموضة والجمال', 'country': 'مصر', 'images': []},
        ]
        counts = {
            'version': 2,
            'physical_with_images': 1234,
            'physical_platforms': {'cezma': 900},
            'physical_categories': {'المنزل والمطبخ': 50},
            'cezma_categories': {'المنزل والمطبخ': 800},
        }
        FakeFTP.listed = [
            'data/cezma_shards/lite_cezma_01.json',
            'data/cezma_shards/lite_cezma_02.json',
        ]
        FakeFTP.files = {
            'data/cezma_shards/lite_cezma_01.json': json.dumps(rows_a, ensure_ascii=False).encode('utf-8'),
            'data/cezma_shards/lite_cezma_02.json': json.dumps(rows_b, ensure_ascii=False).encode('utf-8'),
            'data/catalog_counts.json': json.dumps(counts, ensure_ascii=False).encode('utf-8'),
        }

        with tempfile.TemporaryDirectory() as temp:
            root = temp
            data = os.path.join(root, 'data')
            os.makedirs(data)
            for filename in ('config.php', 'index.php', '.htaccess'):
                with open(os.path.join(root, filename), 'w', encoding='utf-8') as stream:
                    stream.write('test')

            old_root, old_data = update_catalog.ROOT, update_catalog.DATA
            old_changed, old_logs = update_catalog.changed_files, update_catalog.LOG_LINES
            update_catalog.ROOT, update_catalog.DATA = root, data
            update_catalog.changed_files, update_catalog.LOG_LINES = set(), []
            self.addCleanup(setattr, update_catalog, 'ROOT', old_root)
            self.addCleanup(setattr, update_catalog, 'DATA', old_data)
            self.addCleanup(setattr, update_catalog, 'changed_files', old_changed)
            self.addCleanup(setattr, update_catalog, 'LOG_LINES', old_logs)

            uploaded = {}

            def fake_upload(files, cleanup_source_shards=True):
                uploaded['files'] = set(files)
                uploaded['cleanup_source_shards'] = cleanup_source_shards
                return len(files), []

            env = {'FTP_HOST': 'fake', 'FTP_USER': 'user', 'FTP_PASS': 'secret', 'FTP_ROOT': '/htdocs'}
            with mock.patch.dict(os.environ, env, clear=False), \
                 mock.patch('updater.update_catalog.ftplib.FTP', FakeFTP), \
                 mock.patch('updater.update_catalog.ftp_upload', side_effect=fake_upload):
                update_catalog.deploy_cezma_index_only()

            with open(os.path.join(data, 'catalog_counts.json'), encoding='utf-8') as stream:
                saved_counts = json.load(stream)
            with open(os.path.join(data, 'zz_cezma_index', 'zz_manifest.json'), encoding='utf-8') as stream:
                manifest = json.load(stream)

            self.assertEqual(saved_counts['physical_with_images'], 1234)
            self.assertEqual(saved_counts['physical_platforms'], counts['physical_platforms'])
            self.assertEqual(saved_counts['cezma_categories'], counts['cezma_categories'])
            self.assertEqual(saved_counts['cezma_index_signature'], manifest['signature'])
            self.assertEqual(manifest['product_count'], 2)
            self.assertFalse(os.path.exists(os.path.join(data, 'cezma_shards')))
            self.assertFalse(uploaded['cleanup_source_shards'])
            self.assertIn('config.php', uploaded['files'])
            self.assertIn('index.php', uploaded['files'])
            self.assertIn('.htaccess', uploaded['files'])
            self.assertIn('data/catalog_counts.json', uploaded['files'])
            self.assertFalse(any(path.startswith('data/cezma_shards/') for path in uploaded['files']))
            self.assertEqual(len(uploaded['files']), len(manifest['files']) + 1 + 4)


if __name__ == '__main__':
    unittest.main()
