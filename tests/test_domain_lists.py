import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import unittest
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / 'plugin/scripts/Xray/parse-domain-list.py'


class DomainListParserTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        spec = importlib.util.spec_from_file_location('domain_list_parser', SCRIPT)
        if spec is None or spec.loader is None:
            raise RuntimeError('Cannot load domain list parser')
        cls.parser = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(cls.parser)

    def test_normalizes_suffix_lists(self):
        result = self.parser.parse_text('''
# community list
Example.COM
*.cdn.example.org
.assets.example.net
Example.COM.
''')
        self.assertEqual(result, ['assets.example.net', 'cdn.example.org', 'example.com'])

    def test_converts_unicode_to_punycode(self):
        self.assertEqual(self.parser.parse_text('Домен.РФ'), ['xn--d1acufc.xn--p1ai'])

    def test_rejects_invalid_entries(self):
        for value in ['not_a_domain', 'onlylabel', 'bad..example.com', '-bad.example.com',
                      'bad-.example.com', '192.0.2.1', '2001:db8::1', 'regex:^video[0-9]+\\.example$',
                      'regexp:example.com', 'example.*', 'exa mple.com', 'a' * 254]:
            with self.subTest(value=value), self.assertRaises(ValueError):
                self.parser.parse_text(value)

    def test_enforces_entry_limit(self):
        with self.assertRaises(ValueError):
            self.parser.parse_text('a1.example.com\na2.example.com\n', max_entries=1)

    def test_cli_emits_json(self):
        completed = subprocess.run(
            [sys.executable, str(SCRIPT)], input='Example.COM\n*.cdn.example.org\n',
            text=True, capture_output=True, check=True)
        self.assertEqual(json.loads(completed.stdout), ['cdn.example.org', 'example.com'])

    def test_native_routing_list_model_supports_kinds(self):
        model = ET.parse(ROOT / 'plugin/mvc/app/models/OPNsense/Xray/RoutingList.xml').getroot()
        fields = {node.tag for node in model.findall('./items/list/*')}
        self.assertIn('kind', fields)
        self.assertIn('domains', fields)
        kind = model.find('./items/list/kind/OptionValues')
        self.assertIsNotNone(kind)
        self.assertEqual({node.tag for node in ([] if kind is None else list(kind))}, {'ip', 'domain'})
        self.assertEqual(model.findtext('./items/list/kind/Default'), 'ip')

    def test_domain_kind_is_wired_end_to_end(self):
        update = (ROOT / 'plugin/scripts/Xray/update-ip-lists.php').read_text(encoding='utf-8')
        config = (ROOT / 'plugin/scripts/Xray/xray-config.php').read_text(encoding='utf-8')
        lists = (ROOT / 'plugin/scripts/Xray/xray-ip-lists.php').read_text(encoding='utf-8')
        controller = (ROOT / 'plugin/mvc/app/controllers/OPNsense/Xray/Api/RoutingListController.php').read_text(encoding='utf-8')
        partial = (ROOT / 'plugin/mvc/app/views/OPNsense/Xray/partials/tab_routing_lists.volt').read_text(encoding='utf-8')
        service = (ROOT / 'plugin/mvc/app/controllers/OPNsense/Xray/Api/ServiceController.php').read_text(encoding='utf-8')
        checks = (ROOT / 'tools/check.py').read_text(encoding='utf-8')
        ownership = (ROOT / 'plugin/mvc/app/models/OPNsense/Xray/RoutingListRule.php').read_text(encoding='utf-8')
        broker = (ROOT / 'plugin/scripts/Xray/xray-routing.py').read_text(encoding='utf-8')
        self.assertIn('parse-domain-list.py', update)
        self.assertIn('xray_static_domains', config)
        self.assertIn('xray_list_kind', lists)
        self.assertIn('kind', controller)
        self.assertIn('routingListDomains', partial)
        self.assertIn('routingListKind', partial)
        self.assertIn('data-kind', partial)
        self.assertIn("button.attr('data-kind')==='domain'", partial)
        self.assertIn('RoutingListRule::description', service)
        self.assertIn("'domain_lists'", checks)
        # Domain-list deletion wiring: alias-only owned-rule cleanup, cleanup
        # mode (no downloads), synchronous apply, fail-closed rollback and
        # broker orphan purging.
        self.assertIn('class RoutingListRule', ownership)
        self.assertIn('ownedByDomainList', ownership)
        self.assertIn('RoutingListRule::description', service)
        self.assertIn('ownedByDomainList', controller)
        # Deletion and rollback use cleanup mode, never a forced feed refresh.
        self.assertIn('iplistsupdate cleanup', controller)
        self.assertNotIn('iplistsupdate force', controller)
        self.assertIn('configuration history backup', controller)
        self.assertIn('DELETE FROM learned WHERE alias NOT IN', broker)
        self.assertIn('DELETE FROM revisions WHERE alias NOT IN', broker)
        # Deletion must not invoke configd while holding the OPNsense config
        # lock. First remove the active route while its helper rule keeps the
        # alias safely empty, then remove owned rules and apply again.
        delete = controller[controller.index('public function delItemAction'):controller.index('private function restoreDeletedItem')]
        self.assertLess(delete.index('session_write_close();'), delete.index("'xray iplistsupdate cleanup-prepare'"))
        self.assertLess(delete.index("'xray iplistsupdate cleanup-prepare'"), delete.index('ownedByDomainList'))
        # The final alias cleanup reuses cleanup mode and always applies; when
        # it fails after deletion the API still reports result=deleted with
        # cleanup.applied=false so the UI reloads accurately.
        self.assertGreater(delete.rindex("'xray iplistsupdate cleanup'"), delete.index('ownedByDomainList'))
        self.assertNotIn("'xray routingapply'", delete)
        self.assertIn("'result' => 'deleted'", delete)
        self.assertIn("'applied' => false", delete)
        locked = delete[delete.index('$config->lock()'):delete.index('finally {', delete.index('$config->lock()'))]
        self.assertNotIn('configdRun(', locked)
        self.assertNotIn('restoreDeletedItem(', locked)
        # Ownership is alias-only: the marker never embeds the mutable name.
        self.assertIn('Xray domain list [', ownership)
        self.assertIn("'Xray IP list: '", ownership)
        # Cleanup mode performs no downloads, applies unconditionally, prunes
        # only after a successful apply, and drops the apply signature on
        # failure so the next normal updater run retries the apply.
        update = (ROOT / 'plugin/scripts/Xray/update-ip-lists.php').read_text(encoding='utf-8')
        self.assertIn("'cleanup-prepare'", update)
        self.assertIn('$prepareCleanup', update)
        cleanup_branch = update[update.index('if ($cleanup) {'):update.index('} else {', update.index('if ($cleanup) {'))]
        self.assertNotIn('xray_update_ip_list', cleanup_branch)
        self.assertNotIn('$fetch(', cleanup_branch)
        self.assertIn('exec(', cleanup_branch)
        self.assertLess(cleanup_branch.index('exec('), cleanup_branch.index('xray_prune_ip_list_cache'))
        self.assertLess(cleanup_branch.index('xray_write_apply_signature'), cleanup_branch.index('xray_prune_ip_list_cache'))
        self.assertIn('if (!$prepareCleanup)', cleanup_branch)
        self.assertIn('@unlink($signaturePath)', cleanup_branch)
        self.assertNotIn('xray_read_apply_signature', cleanup_branch)
        # Direct API validation rejects inline domains on IP-kind lists.
        self.assertIn('Inline domains apply only to domain routing lists', controller)
        self.assertIn("preg_split('/[,\\r\\n]+/'", controller)
        self.assertIn("preg_split('/[,\\r\\n]+/'", service)


if __name__ == '__main__':
    unittest.main()
