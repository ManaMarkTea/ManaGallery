<?php
$original = attachment_info((int)$artwork['id']);
$glb = attachment_info((int)$artwork['id'], 'glb');
$isModel = $original && $original['model'];
$displayImageUrl = media_url($artwork, !$isModel);
?>
<main class="artwork-page">
<div class="artwork-topline"><a href="/#gallery">← Back to the gallery</a><span><?= e(category_name($data, $artwork)) ?></span></div>
<div class="artwork-layout">
<div class="artwork-image-wrap">
    <a class="artwork-image-link" data-image-open href="<?= e($displayImageUrl) ?>">
        <img class="artwork-image" src="<?= e($displayImageUrl) ?>" alt="<?= e($artwork['title']) ?>">
    </a>
    <?php if ($user && $glb): ?>
        <div class="inline-model" data-model-inline data-model-url="/download/<?= (int)$artwork['id'] ?>?format=glb" hidden>
            <div class="model-stage" data-model-stage></div>
            <div class="model-toolbar">
                <label>View <select data-model-mode aria-label="Model display mode"><option value="materials">Materials</option><option value="wireframe">Wireframe</option><option value="normals">Normals</option><option value="uv">UV coordinates</option><option value="colors">Vertex colors</option></select></label>
                <button type="button" data-model-reset>Reset view</button>
                <button type="button" data-model-open data-model-url="/download/<?= (int)$artwork['id'] ?>?format=glb">Expand viewer</button>
            </div>
            <p class="help" data-model-info>Drag to rotate. Scroll or pinch to zoom.</p>
        </div>
    <?php endif; ?>
</div>
<article class="artwork-info">
<p class="eyebrow">FROM THE COMMUNITY COLLECTION</p><h1><?= e($artwork['title']) ?></h1>
<p><a href="<?= e(gallery_url(['software' => $artwork['software'] ?? 'bryce'])) ?>">Made with <?= e(SOFTWARE[$artwork['software'] ?? 'bryce'] ?? 'Other software') ?></a></p>
<p>Created by <a class="artist-link" href="<?= e(gallery_url(['artist' => $artwork['author'] ?? ''])) ?>"><strong><?= e($artwork['author'] ?: 'Community artist') ?></strong></a></p>
<p class="artwork-description"><?= e($artwork['description'] ?? '') ?></p>
<div class="artwork-stats"><span><strong id="unique-view-count"><?= number_format((int)$artwork['views']) ?></strong>unique views</span>
<?php if (!isset($artwork['ownerId'])): ?><span><strong><?= number_format((int)($artwork['archivedViews'] ?? 0)) ?></strong>unverified legacy hits</span><span><strong><?= e(number_format((float)($artwork['rating'] ?? 0), 1)) ?></strong>archived rating</span><?php endif; ?>
<span><strong><?= e(substr($artwork['date'] ?? 'Unknown', 0, 10)) ?></strong>added</span></div>
<div class="artwork-actions">
<a class="button artwork-view-button" id="view-full" href="<?= e($displayImageUrl) ?>"><?= $isModel ? 'View preview image' : 'View full-size image' ?> ⛶</a>
<?php if ($user): $favorite = (bool)query('SELECT 1 FROM favorites WHERE user_id=? AND artwork_id=?', [$user['id'], $artwork['id']])->fetchColumn(); ?>
<form action="/favorite" method="post"><?= csrf_field() ?><input type="hidden" name="artwork" value="<?= (int)$artwork['id'] ?>"><button><?= $favorite ? '★ Remove favorite' : '☆ Save to favorites' ?></button></form>
<?php else: ?><a href="/login?next=<?= rawurlencode('/art/' . $artwork['id']) ?>">Sign in to save this artwork →</a><?php endif; ?>
<?php if ($original): ?><div class="download-panel"><h2><?= $original['model'] ? 'Model download' : 'Bryce material' ?></h2><p><?= e($original['name']) ?> · <?= number_format($original['bytes'] / 1024, 1) ?> KiB</p>
<?php if ($user): ?><a class="button" href="/download/<?= (int)$artwork['id'] ?>">Download <?= $original['model'] ? strtoupper($original['extension']) . ' model' : '.MAT material' ?> ↓</a><?php if ($glb): ?><button type="button" class="button" data-model-open data-model-url="/download/<?= (int)$artwork['id'] ?>?format=glb">View 3D model</button><?php if (empty($original['nativeGlb'])): ?><a class="button button-quiet" href="/download/<?= (int)$artwork['id'] ?>?format=glb">Download .GLB <?= !empty($original['nativeGlb']) ? 'model' : 'geometry' ?> ↓</a><?php endif; ?><p class="help"><?= !empty($original['nativeGlb']) ? 'Original GLB with embedded materials and textures.' : 'GLB contains geometry and basic colors; external textures and animations are not included.' ?></p><?php endif; ?>
<?php else: ?><a href="/login?next=<?= rawurlencode('/art/' . $artwork['id']) ?>">Sign in to download this file →</a><?php endif; ?></div>
<?php elseif (array_key_exists((string)$artwork['id'], attachment_catalogue('materials')) || array_key_exists((string)$artwork['id'], attachment_catalogue('models'))): ?><p class="help">The original download is missing from the restored archive.</p><?php endif; ?>
</div>
<?php if (!empty($artwork['pending'])): ?><p class="notice" role="status">Awaiting admin approval. Only you and administrators can see this upload.</p><?php endif; ?>
<?php if (!empty($artwork['hidden'])): ?><p class="notice" role="status">Hidden by moderation. Editing does not make this artwork public.</p><?php endif; ?>
<?php if (can_edit($user, $artwork)): ?>
<section class="artwork-management" aria-label="Manage artwork">
<h2>Manage artwork</h2>
<div class="artwork-management-actions">
<a class="button button-quiet" href="/art/<?= (int)$artwork['id'] ?>/edit">Edit artwork →</a>
<?php if ($user['role'] === 'admin'): ?><form action="/admin/visibility" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$artwork['id'] ?>"><button class="quiet-button"><?= !empty($artwork['hidden']) ? 'Unhide artwork' : 'Hide artwork' ?></button></form><?php endif; ?>
<?php if (isset($artwork['ownerId'])): ?><form action="/art/<?= (int)$artwork['id'] ?>/delete" method="post" data-confirm="Delete this uploaded artwork permanently? This cannot be undone."><?= csrf_field() ?><button class="quiet-button artwork-delete">Delete artwork</button></form><?php endif; ?>
</div></section>
<?php endif; ?>
<details class="artwork-view-help"><summary>How views are counted</summary><p>New views count once per account, or per anonymous browser. Review/private views are not counted. No IP addresses are stored for view counts.</p></details>
</article></div>
<dialog id="image-viewer" aria-label="Full-size artwork"><button type="button" id="close-viewer" aria-label="Close full-size image">Close · Esc</button><img src="<?= e($displayImageUrl) ?>" alt="<?= e($artwork['title']) ?>"></dialog>
<?php if ($user && $glb): ?><dialog id="model-viewer" aria-label="3D model viewer"><div class="model-toolbar"><h2><?= e($artwork['title']) ?></h2><label>View <select data-model-mode aria-label="Expanded model display mode"><option value="materials">Materials</option><option value="wireframe">Wireframe</option><option value="normals">Normals</option><option value="uv">UV coordinates</option><option value="colors">Vertex colors</option></select></label><button type="button" data-model-reset>Reset view</button><button type="button" data-model-close>Close</button></div><div class="model-stage" data-model-stage></div><p class="help" data-model-info>Drag to rotate. Scroll or pinch to zoom. Geometry and basic colors only.</p></dialog><?php endif; ?>
<?php require __DIR__ . '/comments.php'; ?></main>
