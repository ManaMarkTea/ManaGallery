"""Exercise a disposable Compose gallery in CI, including a container replacement."""
import http.cookiejar
import os
import re
import subprocess
import urllib.error
import urllib.parse
import urllib.request

if os.environ.get('COMPOSE_PROJECT_NAME') != 'managallery-ci':
    raise SystemExit('This test requires the disposable COMPOSE_PROJECT_NAME=managallery-ci.')
origin = 'http://127.0.0.1:' + str(int(os.environ.get('MANAGALLERY_PORT', '18080')))
client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))


def command(*args):
    return subprocess.check_output(['docker', 'compose', *args])


def request(path, data=None, content_type='application/x-www-form-urlencoded'):
    req = urllib.request.Request(origin + path, data=data, headers={'Origin': origin, 'Content-Type': content_type})
    try:
        with client.open(req, timeout=20) as response:
            return response.status, response.read(), response.geturl()
    except urllib.error.HTTPError as error:
        return error.code, error.read(), error.geturl()


def csrf(path):
    status, body, _ = request(path)
    assert status == 200, (path, status)
    return re.search(rb'name="csrf" value="([^"]+)"', body)[1].decode()


assert request('/')[0] == 200
assert b'Mountain light' not in request('/')[1], 'The container should initialize an empty gallery.'
for private in ['/config.local.php', '/storage/gallery.json', '/src/gallery.php', '/scripts/initialize.php']:
    assert request(private)[0] == 404, private
assert request('/assets/site.css')[0] == 200
data = urllib.parse.urlencode({'csrf': csrf('/register'), 'username': 'docker_owner', 'password': 'fictional docker test password'}).encode()
assert request('/register', data)[2].endswith('/account')
assert request('/admin')[0] == 403, 'Registration must not grant administrator access.'
command('exec', '-T', '--user', 'www-data', 'web', 'php', 'scripts/promote-admin.php', 'docker_owner')
assert request('/admin')[0] == 200
assert b'PHP version' in request('/admin/health')[1]
assert b'apache2handler' in request('/admin/health')[1]
assert request('/upload', b'title=forged')[0] == 403
token = csrf('/upload')
image = command('exec', '-T', '--user', 'www-data', 'web', 'php', '-r', '$i=imagecreatetruecolor(24,24);imagepng($i);')
boundary = 'ManaGalleryDockerTestBoundary'
parts = []
for name, value in {'csrf': token, 'title': 'Docker artwork fixture', 'category': '1', 'software': 'other'}.items():
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="fixture.png"\r\nContent-Type: image/png\r\n\r\n'.encode() + image + b'\r\n')
parts.append(f'--{boundary}--\r\n'.encode())
status, body, artwork = request('/upload', b''.join(parts), 'multipart/form-data; boundary=' + boundary)
assert status == 200 and re.search(r'/art/[0-9]+$', artwork), (status, artwork, body[:500])
assert b'Docker artwork fixture' in body
media = '/media/' + artwork.rsplit('/', 1)[1] + '/preview'
assert request(media)[1].startswith(b'RIFF'), 'GD should produce a WebP preview.'
internal_id = str(int(artwork.rsplit('/', 1)[1]) - 1000000000)
request('/admin/approve', urllib.parse.urlencode({'csrf': csrf('/admin'), 'id': internal_id}).encode())
static_path = re.search(rb'src="(/previews/art-[^"]+)"', request('/')[1])[1].decode()
with client.open(origin + static_path) as response:
    assert response.headers['X-Gallery-Media'] == 'static-preview'
    assert response.headers.get('X-Gallery-Request-ID') is None, 'Apache must bypass the PHP front controller.'
    assert response.headers.get('X-Powered-By') is None, 'Static image must not invoke PHP.'
    assert response.headers['Cache-Control'] == 'private, max-age=300, must-revalidate'
    assert response.read().startswith(b'RIFF')
assert request('/previews/.htaccess')[0] == 403
visibility = {'id': artwork.rsplit('/', 1)[1], 'csrf': csrf('/admin')}
request('/admin/visibility', urllib.parse.urlencode(visibility).encode())
assert request(static_path)[0] == 404, 'Hidden static images must be removed.'
request('/admin/visibility', urllib.parse.urlencode(visibility).encode())
assert request(static_path)[0] == 200, 'Unhidden images must be republished.'
command('up', '-d', '--force-recreate', '--wait')
assert request('/account')[0] == 200, 'Sessions should survive container replacement.'
assert b'docker_owner' in request('/account')[1]
assert b'Docker artwork fixture' in request(urllib.parse.urlsplit(artwork).path)[1]
assert request(media)[1].startswith(b'RIFF'), 'Uploads should survive container replacement.'
assert request('/admin')[0] == 200
assert request(static_path)[0] == 200, 'Static exports must be rebuilt after container replacement.'
print('Docker gallery passed registration, admin setup, upload, routing, CSRF and persistent-data checks.')
