import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'plugin/scripts/Xray/parse-ip-list.py'


class IpListParserTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location('ip_list_parser', SCRIPT)
        if spec is None or spec.loader is None:
            raise RuntimeError('Cannot load IP list parser')
        cls.parser = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.parser)

    def test_normalizes_comments_hosts_cidrs_and_duplicates(self):
        result = self.parser.parse_text('''
# Discord voice
8.8.8.8
1.1.1.42/24  # normalize host bits
2606:4700::5/32
8.8.8.8/32
''')
        self.assertEqual(result, ['1.1.1.0/24', '8.8.8.8/32', '2606:4700::/32'])

    def test_rejects_invalid_or_non_global_entries(self):
        for value in ['not-an-ip', '10.0.0.0/8', '127.0.0.1', '169.254.0.0/16', '::1']:
            with self.subTest(value=value), self.assertRaises(ValueError):
                self.parser.parse_text(value)

    def test_enforces_entry_limit(self):
        with self.assertRaises(ValueError):
            self.parser.parse_text('192.0.2.1\n192.0.2.2\n', max_entries=1, require_global=False)

    def test_cli_emits_json(self):
        completed = subprocess.run(
            [sys.executable, str(SCRIPT)], input='8.8.8.8\n1.1.1.0/24\n',
            text=True, capture_output=True, check=True)
        self.assertEqual(json.loads(completed.stdout), ['1.1.1.0/24', '8.8.8.8/32'])

    def test_native_routing_list_model_exposes_multiple_sources(self):
        model = ET.parse(ROOT / 'plugin/mvc/app/models/OPNsense/Xray/RoutingList.xml').getroot()
        self.assertEqual(model.findtext('mount'), '//OPNsense/xray/routinglists')
        fields = {node.tag for node in model.findall('./items/list/*')}
        self.assertEqual(fields, {
            'enabled', 'name', 'preset', 'kind', 'urls', 'networks', 'domains', 'ports', 'refresh'})
        preset = model.find('./items/list/preset/OptionValues')
        self.assertIsNotNone(preset)
        self.assertEqual({node.tag for node in ([] if preset is None else list(preset))}, {'custom', 'discord_voice'})

    def test_download_and_refresh_entrypoints_are_wired(self):
        update = ROOT / 'plugin/scripts/Xray/update-ip-lists.php'
        self.assertTrue(update.is_file())
        actions = (ROOT / 'plugin/service/conf/actions.d/actions_xray.conf').read_text(encoding='utf-8')
        cron = (ROOT / 'plugin/etc/inc/plugins.inc.d/xray.inc').read_text(encoding='utf-8')
        controller = (ROOT / 'plugin/mvc/app/controllers/OPNsense/Xray/Api/ServiceController.php').read_text(encoding='utf-8')
        checks = (ROOT / 'tools/check.py').read_text(encoding='utf-8')
        install_check = (ROOT / 'tools/check-install.php').read_text(encoding='utf-8')
        self.assertIn('[iplistsupdate]', actions)
        self.assertIn('update-ip-lists.php', cron)
        self.assertIn('ipListsAction', controller)
        self.assertIn("'ip_lists'", checks)
        self.assertIn("'RoutingList'", install_check)

    def test_native_crud_and_routing_tab_are_present(self):
        controller_path = ROOT / 'plugin/mvc/app/controllers/OPNsense/Xray/Api/RoutingListController.php'
        partial_path = ROOT / 'plugin/mvc/app/views/OPNsense/Xray/partials/tab_routing_lists.volt'
        self.assertTrue(controller_path.is_file())
        self.assertTrue(partial_path.is_file())
        controller = controller_path.read_text(encoding='utf-8')
        partial = partial_path.read_text(encoding='utf-8')
        page = (ROOT / 'plugin/mvc/app/views/OPNsense/Xray/general.volt').read_text(encoding='utf-8')
        for method in ['searchItemAction', 'getItemAction', 'addItemAction', 'setItemAction', 'delItemAction', 'toggleItemAction', 'validateInput']:
            self.assertIn(method, controller)
        self.assertIn('UIBootgrid', partial)
        self.assertIn('/api/xray/routingList/', partial)
        self.assertIn('routingListRule', partial)
        self.assertIn("partials/tab_routing_lists", page)
        service = (ROOT / 'plugin/mvc/app/controllers/OPNsense/Xray/Api/ServiceController.php').read_text(encoding='utf-8')
        self.assertIn('setupListRuleAction', service)
        acl = ET.parse(ROOT / 'plugin/mvc/app/models/OPNsense/Xray/ACL/ACL.xml').getroot()
        patterns = {node.text for node in acl.findall('.//pattern')}
        self.assertIn('api/xray/routingList/*', patterns)
        self.assertIn('api/xray/service/ipLists*', patterns)
        self.assertIn('api/xray/service/setupListRule*', patterns)
    def test_review_fixes_are_wired(self):
        control = (ROOT / 'plugin/scripts/Xray/xray-routing-control.php').read_text(encoding='utf-8')
        config = (ROOT / 'plugin/scripts/Xray/xray-config.php').read_text(encoding='utf-8')
        update = (ROOT / 'plugin/scripts/Xray/update-ip-lists.php').read_text(encoding='utf-8')
        lists = (ROOT / 'plugin/scripts/Xray/xray-ip-lists.php').read_text(encoding='utf-8')
        service = (ROOT / 'plugin/mvc/app/controllers/OPNsense/Xray/Api/ServiceController.php').read_text(encoding='utf-8')
        # Stale-but-referenced alias flush must tolerate an absent pf table.
        self.assertIn('xray_is_missing_table_error', config)
        self.assertIn('xray_is_missing_table_error($flushRc', control)
        # Negated alias references (!ALIAS) must be detected for safe flush.
        self.assertIn('function xray_rule_alias_references', config)
        self.assertIn('xray_rule_alias_references', control)
        self.assertNotIn("preg_split('/[,\\s]+/', $value)", control)
        # Orphaned cache files are pruned only for aliases absent from the model.
        self.assertIn('function xray_prune_ip_list_cache', lists)
        self.assertIn('xray_prune_ip_list_cache', update)
        self.assertRegex(lists, r"\^XRAY_\[0-9a-f\]\{12\}\$")
        # Updater applies when the generated routing signature changes.
        self.assertIn('function xray_routing_apply_signature', lists)
        self.assertIn('xray_routing_apply_signature', update)
        self.assertIn('xray_write_apply_signature', update)
        # Missing instances node must not trip a count() TypeError.
        self.assertIn('instances->instance ?? null', service)
    def test_second_review_fixes_are_wired(self):
        lists = (ROOT / 'plugin/scripts/Xray/xray-ip-lists.php').read_text(encoding='utf-8')
        update = (ROOT / 'plugin/scripts/Xray/update-ip-lists.php').read_text(encoding='utf-8')
        controller = (ROOT / 'plugin/mvc/app/controllers/OPNsense/Xray/Api/RoutingListController.php').read_text(encoding='utf-8')
        service = (ROOT / 'plugin/mvc/app/controllers/OPNsense/Xray/Api/ServiceController.php').read_text(encoding='utf-8')
        # Empty-URL lists converge their stale downloaded cache to an empty
        # typed cache before the freshness shortcut can return early, and
        # report the change.
        self.assertLess(lists.index('if (!$urls)'), lists.index('if (!$force && $cached !== null'))
        self.assertIn("'kind' => $kind, 'entries' => []", lists)
        # The updater passes the explicit asset directory instead of a possibly
        # missing XRAY_LOCATION_ASSET environment variable.
        self.assertIn("is_dir('/usr/local/share/xray/current')", update)
        self.assertNotIn('getenv(', update)
        # The routing-list controller accepts only canonical UUIDs.
        self.assertIn(r'/^[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}$/D', controller)
        self.assertNotIn(r'[0-9a-fA-F-]{36}', controller)
        # Disabled matching rules must not block creating an enabled one.
        section = service[service.index('setupListRuleAction'):service.index('setupRuleAction')]
        self.assertIn("(string)$item->enabled === '1'", section)


if __name__ == '__main__':
    unittest.main()
