<?php if($analytics): ?>
<section id="analytics-choice" class="analytics-choice" aria-label="Visitor analytics" hidden>
<div><strong>Help us understand gallery visits</strong><p>Allow Google Analytics to count visits? This is optional and does not enable advertising. <a href="/privacy">Privacy details</a></p></div>
<div class="analytics-choice-actions"><button type="button" id="analytics-allow">Allow analytics</button><button type="button" id="analytics-decline" class="quiet-button">No thanks</button></div>
</section>
<script src="<?= e(asset_url('analytics.js')) ?>" data-analytics-id="<?= e($analytics['id']) ?>" data-page-title="<?= e($analytics['title']) ?>" defer></script>
<?php endif; ?>
