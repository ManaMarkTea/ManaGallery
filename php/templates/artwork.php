<main class="artwork-page">
<div class="artwork-topline"><a href="/#gallery">← Back to the gallery</a><span><?= e(category_name($data,$artwork)) ?></span></div>
<div class="artwork-layout">
<div class="artwork-image-wrap"><img class="artwork-image" src="<?= e(media_url($artwork,true)) ?>" alt="<?= e($artwork['title']) ?>"></div>
<article class="artwork-info">
<p class="eyebrow">FROM THE COMMUNITY COLLECTION</p><h1><?= e($artwork['title']) ?></h1>
<p><a href="<?= e(gallery_url(['software'=>$artwork['software']??'bryce'])) ?>">Made with <?= e(SOFTWARE[$artwork['software']??'bryce']??'Other software') ?></a></p>
<p>Created by <a class="artist-link" href="<?= e(gallery_url(['artist'=>$artwork['author']??''])) ?>"><strong><?= e($artwork['author']?:'Community artist') ?></strong></a></p>
<p class="artwork-description"><?= e($artwork['description']??'') ?></p>
<div class="artwork-stats"><span><strong id="unique-view-count"><?= number_format((int)$artwork['views']) ?></strong>unique views</span>
<?php if (!isset($artwork['ownerId'])): ?><span><strong><?= number_format((int)($artwork['archivedViews']??0)) ?></strong>unverified legacy hits</span><span><strong><?= e(number_format((float)($artwork['rating']??0),1)) ?></strong>archived rating</span><?php endif; ?>
<span><strong><?= e(substr($artwork['date']??'Unknown',0,10)) ?></strong>added</span></div>
<div class="artwork-actions">
<a class="button artwork-view-button" id="view-full" href="<?= e(media_url($artwork,true)) ?>">View full-size image ⛶</a>
<?php if ($user): $favorite=(bool)query('SELECT 1 FROM favorites WHERE user_id=? AND artwork_id=?',[$user['id'],$artwork['id']])->fetchColumn(); ?>
<form action="/favorite" method="post"><?= csrf_field() ?><input type="hidden" name="artwork" value="<?= (int)$artwork['id'] ?>"><button><?= $favorite ? '★ Remove favorite' : '☆ Save to favorites' ?></button></form>
<?php else: ?><a href="/login?next=<?= rawurlencode('/art/'.$artwork['id']) ?>">Sign in to save this artwork →</a><?php endif; ?>
<?php $original=attachment_info((int)$artwork['id']); $glb=attachment_info((int)$artwork['id'],'glb'); ?>
<?php if ($original): ?><div class="download-panel"><h2><?= $original['model'] ? 'Model download' : 'Bryce material' ?></h2><p><?= e($original['name']) ?> · <?= number_format($original['bytes']/1024,1) ?> KiB</p>
<?php if ($user): ?><a class="button" href="/download/<?= (int)$artwork['id'] ?>">Download <?= $original['model'] ? '.3DS model' : '.MAT material' ?> ↓</a><?php if ($glb): ?><a class="button button-quiet" href="/download/<?= (int)$artwork['id'] ?>?format=glb">Download .GLB geometry ↓</a><p class="help">GLB contains geometry and basic colors; external textures and animations are not included.</p><?php endif; ?>
<?php else: ?><a href="/login?next=<?= rawurlencode('/art/'.$artwork['id']) ?>">Sign in to download this file →</a><?php endif; ?></div>
<?php elseif (array_key_exists((string)$artwork['id'],attachment_catalogue('materials')) || array_key_exists((string)$artwork['id'],attachment_catalogue('models'))): ?><p class="help">The original download is missing from the restored archive.</p><?php endif; ?>
</div>
<?php if (!empty($artwork['pending'])): ?><p class="notice" role="status">Awaiting admin approval. Only you and administrators can see this upload.</p><?php endif; ?>
<?php if (!empty($artwork['hidden'])): ?><p class="notice" role="status">Hidden by moderation. Editing does not make this artwork public.</p><?php endif; ?>
<?php if (can_edit($user,$artwork)): ?>
<section class="artwork-management" aria-label="Manage artwork">
<h2>Manage artwork</h2>
<div class="artwork-management-actions">
<a class="button button-quiet" href="/art/<?= (int)$artwork['id'] ?>/edit">Edit artwork →</a>
<?php if ($user['role']==='admin'): ?><form action="/admin/visibility" method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$artwork['id'] ?>"><button class="quiet-button"><?= !empty($artwork['hidden']) ? 'Unhide artwork' : 'Hide artwork' ?></button></form><?php endif; ?>
<?php if (isset($artwork['ownerId'])): ?><form action="/art/<?= (int)$artwork['id'] ?>/delete" method="post" data-confirm="Delete this uploaded artwork permanently? This cannot be undone."><?= csrf_field() ?><button class="quiet-button artwork-delete">Delete artwork</button></form><?php endif; ?>
</div></section>
<?php endif; ?>
<details class="artwork-view-help"><summary>How views are counted</summary><p>New views count once per account, or per anonymous browser. Review/private views are not counted. No IP addresses are stored for view counts.</p></details>
</article></div>
<dialog id="image-viewer" aria-label="Full-size artwork"><button type="button" id="close-viewer" aria-label="Close full-size image">Close · Esc</button><img src="<?= e(media_url($artwork,true)) ?>" alt="<?= e($artwork['title']) ?>"></dialog>
<?php require __DIR__.'/comments.php'; ?></main>
