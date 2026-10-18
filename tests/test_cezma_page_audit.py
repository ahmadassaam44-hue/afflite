import json
import os
import tempfile
import unittest
from unittest.mock import patch

from updater import audit_cezma_pages as audit


class CezmaPageAuditTests(unittest.TestCase):
    @patch.object(audit, 'fetch_bytes')
    def test_audit_one_checks_rendered_product_page_content(self, fetch):
        fetch.return_value = (200, '<html><div>هذا المنتج غير متوفر في الوقت الحالي</div></html>'.encode())
        product_id, url, unavailable, error = audit.audit_one(('337112', 'https://cezma.com/products/store/337112/slug'))
        self.assertEqual(product_id, '337112')
        self.assertTrue(unavailable)
        self.assertEqual(error, '')

    @patch.object(audit, 'fetch_bytes')
    def test_audit_one_reports_failed_http_status(self, fetch):
        fetch.return_value = (503, b'')
        _, _, unavailable, error = audit.audit_one(('123', 'https://cezma.com/products/store/123'))
        self.assertFalse(unavailable)
        self.assertEqual(error, 'HTTP 503')

    def test_push_event_can_request_audit_by_touching_the_audit_workflow(self):
        with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8', delete=False) as stream:
            json.dump({'commits': [{'modified': ['.github/workflows/audit-cezma-pages.yml']} ]}, stream)
            event_path = stream.name
        try:
            with patch.dict(os.environ, {'GITHUB_EVENT_NAME': 'push', 'GITHUB_EVENT_PATH': event_path}):
                self.assertTrue(audit.update_catalog._audit_workflow_changed_in_push())
        finally:
            os.unlink(event_path)

    def test_push_audit_detection_falls_back_to_checked_out_commit_files(self):
        with tempfile.NamedTemporaryFile(mode='w', encoding='utf-8', delete=False) as stream:
            json.dump({'commits': []}, stream)
            event_path = stream.name
        try:
            with patch.dict(os.environ, {
                'GITHUB_EVENT_NAME': 'push', 'GITHUB_EVENT_PATH': event_path, 'GITHUB_SHA': 'deadbeef',
            }), patch('subprocess.check_output', return_value=b'.github/workflows/audit-cezma-pages.yml\n'):
                self.assertTrue(audit.update_catalog._audit_workflow_changed_in_push())
        finally:
            os.unlink(event_path)

    def test_remove_rows_from_shards_removes_only_matching_cezma_ids(self):
        with tempfile.TemporaryDirectory() as data_dir:
            shard_dir = os.path.join(data_dir, 'cezma_shards')
            os.makedirs(shard_dir)
            shard = os.path.join(shard_dir, 'lite_cezma_0.json')
            rows = [
                {'id': 'cezma-337112', 'orig_id': '337112', 'extra': {'cezma_id': 337112}},
                {'id': 'cezma-777', 'orig_id': '777', 'extra': {'cezma_id': 777}},
            ]
            with open(shard, 'w', encoding='utf-8') as stream:
                json.dump(rows, stream)
            with patch.object(audit, 'DATA', data_dir):
                changed = audit.remove_rows_from_shards({'337112'})
            self.assertEqual(changed, [shard])
            with open(shard, encoding='utf-8') as stream:
                self.assertEqual([row['orig_id'] for row in json.load(stream)], ['777'])


if __name__ == '__main__':
    unittest.main()
