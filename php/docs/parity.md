# PHP / Next.js feature comparison

The PHP prototype is a separate application and database, not a live mirror. Account/password and metadata imports preserve verified identity; future changes do not synchronize automatically.

| Area | PHP status |
| --- | --- |
| Archive browsing, software/category/search filters, pagination | Working, with automatic filter updates and 24/48/96 page sizes |
| Artist gallery links | Working |
| Existing Bryce/Next.js password login, roles and verified ownership | Working after import; old verifiers upgraded on first successful login |
| Original trusted publishing permissions | Restored from original group/category ACLs; explicit admin overrides preserved |
| Registration, password changes, disabled accounts | Working; password changes revoke other sessions |
| Passkeys | Pending; Next.js already supports them |
| Email verification/password recovery | Pending in both implementations |
| JPEG/PNG/WebP uploads, approval and editing | Working; PHP currently caps images at 16 MP vs Next.js 40 MP |
| EXIF auto-orientation | Pending in PHP |
| Favorites and owned-archive account collections | Working, with pagination and visibility checks |
| New unique views | Working, server-rendered account/browser deduplication, no IPs; PHP history starts separately |
| Restored comments and new comments/moderation | Working, including profanity/spam/untrusted-link review |
| Original .MAT / .3DS and geometry-only GLB member downloads | Working after private file import, with checksums and access checks |
| Interactive Mana model viewer | Pending in both |
| Admin members/artworks/categories/comments/settings/activity/health | Working; imported members manageable before first PHP sign-in |
| Admin bulk artwork operations and richer reporting | Still to expand |
| AdSense configuration and live integration | Pending in PHP; Next.js implementation available |
| Encrypted scheduled backups and restore checks | Pending for PHP. Next.js VPS backup jobs do not cover the separate PHP deployment |
| Shared-host deployment/update tooling | Shared-hosting scripts with configurable paths; source-only updates verify checksums and retain rollback copies. Automatic GitHub-to-host deployment still pending |
| GitHub source / automated CI | Private ManaMarkTea/ManaGallery repository; source/history publication scan and automated checks exclude runtime data |
| Forums and new live ratings | Pending in both |

Current automated checks cover credentials/migration, trust, sessions, CSRF, moderation, private media, favorites, views, original/GLB transport and checksums, admin permissions/settings/categories, mobile layouts and no-JavaScript filtering. They do not establish that an arbitrary shared host has compatible extensions or that production backup/recovery is configured.
