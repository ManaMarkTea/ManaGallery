<?php

declare(strict_types=1);
putenv('RENDERGALLERY_PHP_DATA_DIR=' . sys_get_temp_dir() . '/mana-comments-' . bin2hex(random_bytes(8)));
require dirname(__DIR__) . '/src/gallery.php';
require dirname(__DIR__) . '/src/database.php';
require dirname(__DIR__) . '/src/comments.php';
foreach ([['First', 100, null, 0], ['Second', 200, null, 0], ['Same time', 200, null, 0], ['Held', 50, 'Review', 0], ['Deleted', 40, null, 1]] as [$body,$created,$reason,$deleted]) {
    query('INSERT INTO comments(artwork_id,username,body,created,moderation_reason,deleted) VALUES (1,?,?,?,?,?)', ['Fictional artist', $body, $created, $reason, $deleted]);
}
$check = function (bool $ok): void {
    if (!$ok) {
        throw new RuntimeException('Comment ordering failed');
    }
};
$check(array_column(visible_comments(1), 'body') === ['First', 'Second', 'Same time']);
$check(array_column(visible_comments(1, 'newest'), 'body') === ['Same time', 'Second', 'First']);
$check(array_column(visible_comments(1, 'DESC; DROP TABLE comments'), 'body') === ['First', 'Second', 'Same time']);
$check(comment_order([]) === 'oldest' && comment_order(['commentSort' => ['invalid']]) === 'oldest');
$check(comment_order(['commentSort' => 'newest']) === 'newest');
echo "Comment ordering, tie breaks and moderation visibility passed.\n";
