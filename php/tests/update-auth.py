"""Exercise updater authorization through HTTP against an isolated gallery."""
import http.cookiejar
import json
import os
import re
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

root = Path(__file__).resolve().parents[1]
php = os.environ.get('PHP_BINARY', 'php')
with tempfile.TemporaryDirectory(prefix='gallery-update-http-') as temporary:
    app = Path(temporary) / 'app'
    app.mkdir()
    for name in ['src', 'templates', 'public']:
        shutil.copytree(root / name, app / name)
    for name in ['config.example.php', 'router.php']:
        shutil.copy2(root / name, app / name)
    env = {**os.environ, 'RENDERGALLERY_PHP_DATA_DIR': str(app / 'storage')}
    fixture = '''require 'src/gallery.php'; require 'src/database.php';
    foreach (['admin','member'] as $role) {
      query('INSERT INTO users(username,username_key,password,role,created) VALUES (?,?,?,?,?)',
        [$role,$role,password_hash('Fictional test password',PASSWORD_DEFAULT),$role,time()]);
    }'''
    subprocess.run([php, '-r', fixture], cwd=app, env=env, check=True)
    with socket.socket() as probe:
        probe.bind(('127.0.0.1', 0))
        port = probe.getsockname()[1]
    base = f'http://127.0.0.1:{port}'
    with (Path(temporary) / 'server.log').open('w+') as log:
        server = subprocess.Popen([php, '-S', f'127.0.0.1:{port}', '-t', 'public', 'router.php'], cwd=app, env=env, stdout=log, stderr=log)
        try:
            class NoRedirect(urllib.request.HTTPRedirectHandler):
                def redirect_request(self, req, fp, code, msg, headers, newurl):
                    return None

            def client():
                return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NoRedirect())

            def request(browser, path, data=None):
                payload = None if data is None else urllib.parse.urlencode(data).encode()
                try:
                    response = browser.open(base + path, payload, timeout=15)
                except urllib.error.HTTPError as error:
                    response = error
                return response.code, response.headers, response.read().decode()

            anonymous = client()
            for attempt in range(50):
                try:
                    request(anonymous, '/login')
                    break
                except urllib.error.URLError:
                    time.sleep(0.1)
            status, headers, _ = request(anonymous, '/admin/updates')
            assert status == 303 and headers['Location'] == '/login'
            for action in ['check', 'install']:
                assert request(anonymous, '/admin/updates/' + action, {})[0] == 303

            for role in ['member', 'admin']:
                browser = client()
                body = request(browser, '/login')[2]
                csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', body)[1]
                assert request(browser, '/login', {'csrf': csrf, 'username': role, 'password': 'Fictional test password'})[0] == 303
                status, _, body = request(browser, '/admin/updates')
                assert status == (200 if role == 'admin' else 403), body[:1000]
                if role == 'member':
                    assert request(browser, '/admin/updates/check', {'csrf': csrf})[0] == 403
                    continue
                csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', body)[1]
                assert request(browser, '/admin/updates/install', {'csrf': 'invalid'})[0] == 403
                assert request(browser, '/admin/updates/install', {'csrf': csrf, 'password': 'incorrect'})[0] == 303
                assert 'Confirm your current password' in request(browser, '/admin/updates')[2]
                assert request(browser, '/admin/updates/install', {'csrf': csrf, 'password': 'Fictional test password'})[0] == 303
                assert 'review the release first' in request(browser, '/admin/updates')[2]
                assert 'gallery database and journals' in request(browser, '/admin')[2]
                assert 'Storage breakdown' in request(browser, '/admin/health')[2]
                assert request(browser, '/admin/storage/scan', {'csrf': 'invalid'})[0] == 403
                assert request(browser, '/admin/storage/scan', {'csrf': csrf})[0] == 303
                assert 'Unique file bytes' in request(browser, '/admin/health')[2]
                error_log = app / 'storage/error_log'
                old_error = json.dumps({'requestId': 'a' * 16, 'message': 'Fictional historical error 001'}) + '\n'
                new_error = json.dumps({'requestId': 'b' * 16, 'message': 'Fictional new error 002'}) + '\n'
                error_log.write_text(old_error)
                assert 'Fictional historical error 001' in request(browser, '/admin/health')[2]
                assert request(browser, '/admin/errors/clear', {'csrf': 'invalid'})[0] == 403
                assert request(browser, '/admin/errors/clear', {'csrf': csrf})[0] == 303
                assert error_log.read_text() == old_error, 'Clearing must retain the diagnostic log'
                assert 'Fictional historical error 001' not in request(browser, '/admin/health')[2]
                with error_log.open('a') as output:
                    output.write(new_error)
                body = request(browser, '/admin/health')[2]
                assert 'Fictional new error 002' in body and 'Fictional historical error 001' not in body
                error_log.replace(app / 'storage/rotated-log')
                error_log.write_text(new_error)
                assert 'Fictional new error 002' in request(browser, '/admin/health')[2], 'Rotated logs must remain visible'

            print('Updater HTTP admin/member access, CSRF and password checks passed.')
        finally:
            server.terminate()
            server.wait(timeout=10)
