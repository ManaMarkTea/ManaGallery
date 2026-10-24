<?php
$editing = $artwork !== null;
$defaults = $editing ? ['title' => $artwork['title'], 'description' => $artwork['description'] ?? '', 'category' => (string)$artwork['categoryId'], 'software' => $artwork['software'] ?? 'bryce', 'tags' => $artwork['tags'] ?? ''] : ['title' => '', 'description' => '', 'category' => '', 'software' => 'bryce', 'tags' => ''];
$value = fn ($key) => $method === 'POST' ? param($_POST, $key) : $defaults[$key];
?>
<main class="prototype-form-page wide-form"><a href="<?= $editing ? '/art/' . (int)$artwork['id'] : '/account' ?>">← Back</a><p class="eyebrow"><?= $editing ? 'YOUR ARTWORK' : 'SHARE YOUR NEXT WORLD' ?></p><h1><?= $editing ? 'Edit artwork' : 'Upload artwork' ?></h1>
<?php if (!$editing && !site_settings()['uploadsOpen']): ?><p class="notice">Uploads are currently paused.</p><?php endif; ?><?php if (!$editing): ?><p><?= $user['auto_approve'] ? 'Your future uploads are automatically approved.' : 'Your upload will stay private until an administrator approves it.' ?></p><p class="help"><?= number_format(upload_usage((int)$user['id']) / 1048576, 1) ?> MiB used of <?= number_format(site_settings()['memberQuotaMiB']) ?> MiB. JPEG, PNG or WebP, up to 10 MiB and 16 megapixels.</p><?php endif; ?>
<?php if ($editing && !empty($artwork['hidden'])): ?><p class="notice">This artwork is hidden by moderation. Editing it will not publish it.</p><?php endif; ?>
<?php if ($formError): ?><p class="form-error" role="alert"><?= e($formError) ?><?= !$editing ? ' Please select the image again before resubmitting.' : '' ?></p><?php endif; ?>
<form class="prototype-form" method="post" enctype="multipart/form-data" action="<?= e($path) ?>"><?= csrf_field() ?>
<label>Title<input name="title" value="<?= e($value('title')) ?>" maxlength="160" required></label>
<label>Description<textarea aria-label="Description" name="description" maxlength="10000" rows="7"><?= e($value('description')) ?></textarea></label>
<div class="form-pair"><label>Category<select aria-label="Category" name="category" required><option value="">Choose a category</option><?php foreach ($all['categories'] as $category): ?><option value="<?= (int)$category['id'] ?>" <?= $value('category') === (string)$category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
<label>Software<select aria-label="Software" name="software"><?php foreach (SOFTWARE as $id => $name): ?><option value="<?= e($id) ?>" <?= $value('software') === $id ? 'selected' : '' ?>><?= e($name) ?></option><?php endforeach; ?></select></label></div>
<label>Tags<input name="tags" value="<?= e($value('tags')) ?>" maxlength="500" placeholder="landscape, clouds, mountains"></label>
<?php if (!$editing): ?><label>Artwork image<input name="image" type="file" accept="image/jpeg,image/png,image/webp" required></label><p class="help">The server decodes and re-encodes images as WebP and creates a preview. Embedded metadata is removed; keep your original file separately.</p><?php endif; ?>
<button type="submit"><?= $editing ? 'Save changes' : ($user['auto_approve'] ? 'Publish artwork' : 'Submit for review') ?></button></form></main>
