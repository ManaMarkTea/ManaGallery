"""OAuth permission and callback checks without contacting Google."""
import importlib.util
import json
from pathlib import Path
import threading
import unittest
import urllib.error
import urllib.parse
import urllib.request

spec = importlib.util.spec_from_file_location('connect_drive', Path(__file__).resolve().parents[1] / 'scripts/connect-drive.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ConnectionTests(unittest.TestCase):
    def test_authorization_is_limited_and_uses_pkce(self):
        url = module.authorization('fixture.apps.googleusercontent.com', 'http://127.0.0.1:1234/', 'random-state', 'random-verifier')
        query = urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)
        self.assertEqual(query['scope'], [module.SCOPE])
        self.assertEqual(query['include_granted_scopes'], ['false'])
        self.assertEqual(query['code_challenge_method'], ['S256'])
        self.assertEqual(query['access_type'], ['offline'])
        self.assertNotIn('random-verifier', url)

    def test_expanded_permissions_are_not_saved(self):
        calls = []
        def request(url, body, headers):
            calls.append(url)
            return {'scope': 'https://www.googleapis.com/auth/drive', 'refresh_token': 'fictional', 'access_token': 'fictional'}
        with self.assertRaisesRegex(RuntimeError, 'exactly drive.file'):
            module.connect({'client_id': 'fictional', 'client_secret': 'fictional'}, 'code', 'http://127.0.0.1:1234/', 'verifier', 'Backups', request)
        self.assertEqual(len(calls), 1)

    def test_only_new_app_folder_is_created(self):
        calls = []
        def request(url, body, headers):
            calls.append((url, body, headers))
            if len(calls) == 1:
                self.assertEqual(urllib.parse.parse_qs(body.decode())['code_verifier'], ['verifier'])
                return {'scope': module.SCOPE, 'refresh_token': 'private-refresh', 'access_token': 'ephemeral-access'}
            self.assertNotIn('parents', json.loads(body))
            return {'id': 'app-created-folder'}
        connection = module.connect({'client_id': 'fictional', 'client_secret': 'private-client'}, 'code', 'http://127.0.0.1:1234/', 'verifier', 'Backups', request)
        self.assertEqual(connection['folderId'], 'app-created-folder')
        self.assertNotIn('ephemeral-access', json.dumps(connection))
        self.assertEqual(len(calls), 2)

    def test_callback_rejects_forged_state_and_host(self):
        with module.HTTPServer(('127.0.0.1', 0), module.Callback) as server:
            server.state, server.result = 'correct-state', None
            server.expected_host = f'127.0.0.1:{server.server_port}'
            for query, host, expected in [('state=wrong&code=secret', server.expected_host, 400),
                                          ('state=correct-state&code=secret', 'evil.invalid', 400),
                                          ('state=correct-state&code=secret', server.expected_host, 200)]:
                thread = threading.Thread(target=server.handle_request)
                thread.start()
                request = urllib.request.Request(f'http://{server.expected_host}/?{query}', headers={'Host': host})
                try:
                    response = urllib.request.urlopen(request)
                except urllib.error.HTTPError as error:
                    response = error
                self.assertEqual(response.status, expected)
                self.assertEqual(response.headers['Cache-Control'], 'no-store')
                response.close()
                thread.join()
                if expected == 400:
                    self.assertIsNone(server.result)
            self.assertEqual(server.result, {'code': 'secret'})


if __name__ == '__main__':
    unittest.main()
