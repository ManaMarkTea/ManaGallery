# Third-party components

The repository's MIT license covers original project code and assets.
Dependencies retain their own licenses; the project license does not replace them.

- PHP authenticated mail uses PHPMailer, licensed under LGPL-2.1. Its Composer
  package includes its copyright and license files. Keep those notices when
  distributing dependencies. See https://github.com/PHPMailer/PHPMailer.
- Passkeys use lbuchs/WebAuthn under the MIT license, including the upstream
  notices for its binary buffer and CBOR helpers. See
  https://github.com/lbuchs/WebAuthn.
- Playwright and other test dependencies retain the licenses included in their
  npm packages. See each package's `LICENSE` and package metadata.
- The model viewer and offline converter use Three.js under the MIT license.
  Its notice is distributed in `php/public/assets/three-LICENSE.txt`; bundled
  notices accompany `model-viewer.js`. See https://github.com/mrdoob/three.js.
- Open-source fonts, if used by the selected theme, retain their respective font
  licenses and are not relicensed by this project.

Locked package manifests identify the versions used by the application and its tests.
Dependency directories are not committed; installation retrieves them with their
upstream notices. Gallery artwork, community comments, uploaded models and user
data are not part of this source distribution or its MIT license.

Legacy application source, proprietary compatibility patches, live database dumps
and original site templates are excluded from the source distribution. Data-format
migration support is separate from redistribution of a legacy application.
