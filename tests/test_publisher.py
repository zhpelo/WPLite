import importlib.util
from pathlib import Path
import sys
import unittest


TOOLS = Path(__file__).resolve().parents[1] / 'tools'
sys.path.insert(0, str(TOOLS))
spec = importlib.util.spec_from_file_location('publisher', TOOLS / 'publish_sync_pr.py')
p = importlib.util.module_from_spec(spec)
spec.loader.exec_module(p)


class PublicationPolicyTest(unittest.TestCase):
    def test_accept_checkout_origin_forms_but_not_other_push_targets(self):
        for url in ['https://github.com/example/WPLite', 'https://github.com/example/WPLite.git']:
            self.assertTrue(p.approved_origin(url, 'example/WPLite'))
        for url in ['https://github.com/other/WPLite.git', 'https://evil.invalid/example/WPLite',
                    'https://github.com/example/WPLite.git/extra', 'file:///tmp/repository']:
            self.assertFalse(p.approved_origin(url, 'example/WPLite'))

    def test_only_our_identified_sync_prs_can_be_superseded(self):
        pr = {'body': p.MARKER, 'head': {'ref': 'bot/sync-wp-7.1.3-a_b1', 'repo': {'full_name': 'example/WPLite'}}}
        self.assertTrue(p.is_managed_pr(pr, 'example/WPLite'))
        self.assertEqual(p.pr_target(pr), '7.1.3')
        self.assertFalse(p.is_managed_pr(pr, 'other/WPLite'))
        pr['body'] = 'A manually created PR'
        self.assertFalse(p.is_managed_pr(pr, 'example/WPLite'))

    def test_driver_only_candidates_do_not_hide_or_close_newer_driver_work(self):
        pr = {'body': p.MARKER, 'head': {'ref': 'bot/sync-wp-7.1.3-sqlite-3.1.1-a_b1', 'repo': {'full_name': 'example/WPLite'}}}
        self.assertTrue(p.is_managed_pr(pr, 'example/WPLite'))
        self.assertEqual(p.pr_driver(pr), '3.1.1')
        self.assertTrue(p.superseded(pr, '7.1.3', '3.1.2'))
        self.assertFalse(p.superseded(pr, '7.1.4', '3.1.0'))
        self.assertFalse(p.superseded(pr, '7.1.3', '3.1.1'))
        pr['body'] = p.MARKER
        pr['head']['repo'] = None
        self.assertFalse(p.is_managed_pr(pr, 'example/WPLite'))


if __name__ == '__main__':
    unittest.main()
