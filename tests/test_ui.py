from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
VIEWS = ROOT / 'plugin/mvc/app/views/OPNsense/Xray'


class UiCosmeticTests(unittest.TestCase):
    def test_all_bootgrids_default_to_at_least_twenty_rows(self):
        matches = []
        for path in VIEWS.rglob('*.volt'):
            text = path.read_text(encoding='utf-8')
            matches.extend((path.name, value) for value in re.findall(r'rowCount\s*:\s*(\[[^\]]+\])', text))
        self.assertGreaterEqual(len(matches), 3)
        for filename, value in matches:
            with self.subTest(filename=filename, row_count=value):
                self.assertEqual(value.replace(' ', ''), '[20,50,-1]')

    def test_empty_hash_from_advanced_toggle_keeps_current_tab(self):
        scripts = (VIEWS / 'partials/scripts.volt').read_text(encoding='utf-8')
        self.assertIn('function showMainTab(useDefault)', scripts)
        self.assertIn("if (!hash && useDefault) hash = '#general';", scripts)
        self.assertIn('if (!hash) return;', scripts)
        self.assertIn("$(window).on('hashchange', function () { showMainTab(false); });", scripts)
        self.assertIn('showMainTab(true);', scripts)


if __name__ == '__main__':
    unittest.main()
