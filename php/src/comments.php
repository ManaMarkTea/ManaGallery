<?php

declare(strict_types=1);
function comment_reason(string $body, bool $trusted = false): ?string
{
    $rules = json_decode(file_get_contents(__DIR__ . '/comment-rules.json'), true, 512, JSON_THROW_ON_ERROR);
    $text = mb_strtolower(preg_replace('/[\x{200b}\x{200c}\x{200d}\x{feff}]/u', '', $body), 'UTF-8');
    foreach ($rules['profanity'] as $word) {
        if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u', $text)) {
            return 'Possible profanity';
        }
    }
    foreach ($rules['spamPhrases'] as $phrase) {
        if (str_contains($text, $phrase)) {
            return 'Possible advertising spam';
        }
    }
    if (!$trusted && preg_match('~https?://|www\.|\[url~i', $text)) {
        return 'Links need review';
    }
    if (preg_match('/(.)\1{29,}/us', $text)) {
        return 'Repetitive text';
    }

    return null;
}
function comment_order(array $input): string
{
    return param($input, 'commentSort') === 'newest' ? 'newest' : 'oldest';
}
function visible_comments(int $artwork, string $order = 'oldest'): array
{
    $direction = $order === 'newest' ? 'DESC' : 'ASC';

    return query('SELECT c.*,COALESCE(c.user_id,i.local_user_id) AS owner_id FROM comments c LEFT JOIN imported_accounts i ON i.identity=c.import_identity WHERE artwork_id=? AND deleted=0 AND moderation_reason IS NULL ORDER BY created ' . $direction . ',id ' . $direction . ' LIMIT 500', [$artwork])->fetchAll();
}
function comment_action(string $path, array $user, array $all): bool
{
    if (!in_array($path, ['/comment', '/comment/delete'], true)) {
        return false;
    }
    $returnSort = comment_order($_POST) === 'newest' ? '?commentSort=newest' : '';
    if ($path === '/comment/delete') {
        $comment = query('SELECT c.*,COALESCE(c.user_id,i.local_user_id) AS owner_id FROM comments c LEFT JOIN imported_accounts i ON i.identity=c.import_identity WHERE c.id=? AND deleted=0', [(int)param($_POST, 'id')])->fetch();
        if (!$comment || ($user['role'] !== 'admin' && (int)$comment['owner_id'] !== (int)$user['id'])) {
            http_response_code(404);
            exit('Comment not found');
        }
        query('UPDATE comments SET deleted=1 WHERE id=?', [$comment['id']]);
        log_activity((int)$user['id'], 'Comment deleted', (string)$comment['id']);
        redirect_to('/art/' . $comment['artwork_id'] . $returnSort . '#comments');
    }
    $artwork = find_artwork($all, (int)param($_POST, 'artwork'));
    if (!$artwork || !can_view($user, $artwork)) {
        http_response_code(404);
        exit('Artwork not found');
    }
    $body = param($_POST, 'body');
    if (!$body || mb_strlen($body) > 4000) {
        $_SESSION['notice'] = 'Write a comment between 1 and 4,000 characters.';
        redirect_to('/art/' . $artwork['id'] . $returnSort . '#comments');
    }
    take_attempt('comment', (string)$user['id'], 30, 3600);
    if (query('SELECT 1 FROM comments WHERE artwork_id=? AND user_id=? AND body=? AND deleted=0', [$artwork['id'], $user['id'], $body])->fetchColumn()) {
        $_SESSION['notice'] = 'You already posted this comment.';
        redirect_to('/art/' . $artwork['id'] . $returnSort . '#comments');
    }
    $reason = comment_reason($body, (bool)$user['auto_approve'] || $user['role'] === 'admin');
    with_write(function () use ($artwork, $user, $body, $reason, $all) {
        query('INSERT INTO comments(artwork_id,user_id,username,body,created,moderation_reason) VALUES (?,?,?,?,?,?)', [$artwork['id'], $user['id'], $user['username'], $body, time(), $reason]);
        notification_enqueue((int)database()->lastInsertId(), $all);
    });
    log_activity((int)$user['id'], $reason ? 'Comment held for review' : 'Comment posted', (string)$artwork['id']);
    $_SESSION['notice'] = $reason ? 'Your comment was submitted for admin review.' : 'Comment posted.';
    redirect_to('/art/' . $artwork['id'] . $returnSort . '#comments');
}
