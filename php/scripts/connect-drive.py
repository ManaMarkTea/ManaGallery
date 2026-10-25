"""Connect a desktop Google OAuth client using only Drive app-created-files access."""
import argparse
import base64
import hashlib
import hmac
from http.server import BaseHTTPRequestHandler, HTTPServer
import json
import os
from pathlib import Path
import secrets
import time
import urllib.error
import urllib.parse
import urllib.request
import webbrowser

SCOPE = 'https://www.googleapis.com/auth/drive.file'


def authorization(client_id, redirect_uri, state, verifier):
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip('=')
    return 'https://accounts.google.com/o/oauth2/v2/auth?' + urllib.parse.urlencode({
        'client_id': client_id, 'redirect_uri': redirect_uri, 'response_type': 'code',
        'scope': SCOPE, 'state': state, 'code_challenge': challenge,
        'code_challenge_method': 'S256', 'access_type': 'offline',
        'prompt': 'consent', 'include_granted_scopes': 'false',
    })


class Callback(BaseHTTPRequestHandler):
    def log_message(self, *_):
        pass  # Authorization codes and state must never appear in logs.

    def do_GET(self):
        parsed = urllib.parse.urlsplit(self.path)
        values = urllib.parse.parse_qs(parsed.query)
        valid = (parsed.path == '/' and self.headers.get('Host') == self.server.expected_host
                 and len(values.get('state', [])) == 1
                 and hmac.compare_digest(values['state'][0], self.server.state))
        if not valid:
            self.send_response(400)
            message = b'Invalid authorization callback. Return to the original browser tab.'
        elif values.get('error'):
            self.server.result = {'error': True}
            self.send_response(400)
            message = b'Authorization was not completed. You can close this tab.'
        elif len(values.get('code', [])) != 1 or not 1 <= len(values['code'][0]) <= 4096:
            self.send_response(400)
            message = b'No valid authorization code received.'
        else:
            self.server.result = {'code': values['code'][0]}
            self.send_response(200)
            message = b'Authorization received. Return to the terminal to finish connecting Drive.'
        self.send_header('Content-Type', 'text/plain; charset=utf-8')
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Referrer-Policy', 'no-referrer')
        self.send_header('Content-Length', str(len(message)))
        self.end_headers()
        self.wfile.write(message)


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_):
        raise RuntimeError('Unexpected Google API redirect. Connection was not saved.')


def google_json(url, data, headers=None):
    request = urllib.request.Request(url, data=data, headers=headers or {}, method='POST')
    try:
        with urllib.request.build_opener(NoRedirect).open(request, timeout=30) as response:
            return json.load(response)
    except urllib.error.HTTPError as error:
        # Google's response can echo credentials. Report only the status.
        raise RuntimeError(f'Google rejected the request (HTTP {error.code}). Check the OAuth client and enabled Drive API.') from None


def connect(client, code, redirect_uri, verifier, folder_name, request=google_json):
    token = request('https://oauth2.googleapis.com/token', urllib.parse.urlencode({
        'grant_type': 'authorization_code', 'client_id': client['client_id'],
        'client_secret': client['client_secret'], 'code': code,
        'redirect_uri': redirect_uri, 'code_verifier': verifier,
    }).encode(), {'Content-Type': 'application/x-www-form-urlencoded'})
    if token.get('scope') != SCOPE:
        raise RuntimeError('Google did not confirm exactly drive.file access. Connection was not saved.')
    if not token.get('refresh_token') or not token.get('access_token'):
        raise RuntimeError('Google did not return offline access. Revoke this app connection and try again.')
    folder = request('https://www.googleapis.com/drive/v3/files?fields=id', json.dumps({
        'name': folder_name, 'mimeType': 'application/vnd.google-apps.folder',
    }).encode(), {'Authorization': 'Bearer ' + token['access_token'], 'Content-Type': 'application/json'})
    if not folder.get('id'):
        raise RuntimeError('Google did not create the backup folder.')
    return {'clientId': client['client_id'], 'clientSecret': client['client_secret'],
            'refreshToken': token['refresh_token'], 'folderId': folder['id']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('client', type=Path, help='Downloaded Desktop OAuth client JSON')
    parser.add_argument('output', type=Path, help='New private connection JSON, outside the web root')
    parser.add_argument('--folder', default='ManaGallery backups')
    args = parser.parse_args()
    if args.output.exists() or args.output.is_symlink():
        raise RuntimeError('The output already exists. Choose a new private filename.')
    if any(part.lower() in ('public', 'public_html', 'public_html_sub') for part in args.output.resolve().parts):
        raise RuntimeError('Save the connection outside the public web root.')
    if not 1 <= len(args.folder) <= 80:
        raise RuntimeError('Use a folder name between 1 and 80 characters.')
    client = json.loads(args.client.read_text(encoding='utf-8')).get('installed', {})
    if not isinstance(client.get('client_id'), str) or not client['client_id'].endswith('.apps.googleusercontent.com') or not client.get('client_secret'):
        raise RuntimeError('Use the downloaded JSON for a Desktop app OAuth client.')
    state, verifier = secrets.token_urlsafe(32), secrets.token_urlsafe(48)
    with HTTPServer(('127.0.0.1', 0), Callback) as server:
        server.expected_host = f'127.0.0.1:{server.server_port}'
        server.state, server.result, server.timeout = state, None, 1
        redirect_uri = f'http://{server.expected_host}/'
        url = authorization(client['client_id'], redirect_uri, state, verifier)
        print('Open this link and approve app-created-files access only:\n' + url, flush=True)
        webbrowser.open(url)
        deadline = time.monotonic() + 900
        while server.result is None and time.monotonic() < deadline:
            server.handle_request()
        if not server.result or server.result.get('error'):
            raise RuntimeError('Authorization was cancelled or timed out. No connection was saved.')
        connection = connect(client, server.result['code'], redirect_uri, verifier, args.folder)
    args.output.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    descriptor = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'w', encoding='utf-8') as output:
        json.dump(connection, output, indent=2)
    print('Connection saved privately. Merge its four fields into the server backup settings over SSH.')
    print('The new app-created folder is in My Drive. Existing backups were not changed.')


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, OSError, ValueError) as error:
        raise SystemExit(str(error)) from None
