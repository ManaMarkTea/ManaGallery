<details class="prototype-panel category-create">
    <summary><strong>Add a category</strong></summary>
    <form class="prototype-form" action="/admin/category" method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label>Name<input name="name" maxlength="100" required></label>
        <label>Description<textarea name="description" maxlength="2000" rows="2"></textarea></label>
        <label class="checkbox-field"><input type="checkbox" name="hideFromList">Hide from category lists</label>
        <?php $discordCategoryId = 0;
        require __DIR__ . '/category-discord.php'; ?>
        <button>Create category</button>
    </form>
</details>
<p class="category-help">Hidden categories keep their artwork and existing links. For Discord, create a webhook under Channel &gt; Edit Channel &gt; Integrations &gt; Webhooks. <a href="/admin/settings">Discord defaults</a>.</p>
<?php foreach ($all['categories'] as $c): ?>
    <details class="prototype-panel category-editor" data-category-id="<?= (int)$c['id'] ?>">
        <summary><strong><?= e($c['name']) ?></strong> &middot; <?= count(array_filter($all['items'], fn ($i) => (int)$i['categoryId'] === (int)$c['id'])) ?> artworks<?= !empty($c['hiddenFromList']) ? ' &middot; Hidden from lists' : '' ?></summary>
        <form class="prototype-form" action="/admin/category" method="post" autocomplete="off" data-category-save>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <label>Name<input name="name" value="<?= e($c['name']) ?>" maxlength="100" required></label>
            <label>Description<textarea name="description" maxlength="2000" rows="2"><?= e($c['description'] ?? '') ?></textarea></label>
            <label class="checkbox-field"><input type="checkbox" name="hideFromList" <?= !empty($c['hiddenFromList']) ? 'checked' : '' ?>>Hide from category lists</label>
            <?php $discordCategoryId = (int)$c['id'];
    require __DIR__ . '/category-discord.php'; ?>
            <button>Save category</button>
        </form>
        <form action="/admin/category" method="post" data-confirm="Delete this empty category?">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="hidden" name="action" value="delete">
            <button class="quiet-button">Delete empty category</button>
        </form>
    </details>
<?php endforeach; ?>
