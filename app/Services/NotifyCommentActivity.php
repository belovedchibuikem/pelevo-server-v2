<?php

namespace App\Services;

use App\Mail\PelevoNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class NotifyCommentActivity
{
    public function created(object $comment, string $authorName): void
    {
        $authorId = (string) $comment->user_id;
        $snippet = Str::limit(trim((string) $comment->body), 120);
        if ($snippet === '') {
            return;
        }
        $data = $this->payload($comment);
        $parentId = $comment->parent_id ?? null;
        if (is_string($parentId) && $parentId !== '') {
            $parent = DB::table('comments')->where('id', $parentId)->first();
            if ($parent && (string) $parent->user_id !== $authorId) {
                $this->notify(
                    (string) $parent->user_id,
                    'reply',
                    'reply:'.$comment->id,
                    $authorName.' replied to your comment',
                    $snippet,
                    $authorName.' replied',
                    $authorName.' replied to your comment on Pelevo.',
                    $data,
                );
            }

            return;
        }

        $creatorId = $this->creatorUserId((string) $comment->commentable_type, (string) $comment->commentable_id);
        if ($creatorId === null || $creatorId === $authorId) {
            return;
        }
        $label = $data['content_title'] ?? 'your podcast';
        $this->notify(
            $creatorId,
            'comment',
            'comment:'.$comment->id,
            $authorName.' commented on '.$label,
            $snippet,
            'New comment on '.$label,
            $authorName.' left a comment on your content in Pelevo.',
            $data,
        );
    }

    /**
     * @param  array<string, string>  $data
     */
    private function notify(
        string $userId,
        string $type,
        string $key,
        string $title,
        string $body,
        string $heading,
        string $intro,
        array $data,
    ): void {
        try {
            app(InAppNotificationDelivery::class)->deliver($userId, [
                'type' => $type,
                'key' => $key,
                'title' => $title,
                'body' => $body,
                'data' => $data,
            ]);
        } catch (\Throwable $error) {
            Log::warning('comment.notification_failed', ['error' => $error->getMessage(), 'type' => $type]);
        }

        $footer = 'You received this because Email Notifications is on in Pelevo. Turn it off in Settings to stop these emails.';
        app(MailPreference::class)->queueAlert($userId, new PelevoNotice(
            subjectLine: $title,
            eyebrow: $type === 'reply' ? 'Reply' : 'Comment',
            heading: $heading,
            intro: $intro,
            detail: $body,
            actionLabel: 'Open Pelevo',
            actionUrl: config('app.url'),
            footerNote: $footer,
        ));
    }

    /**
     * @return array<string, string>
     */
    private function payload(object $comment): array
    {
        $type = (string) $comment->commentable_type;
        $id = (string) $comment->commentable_id;
        $data = [
            'comment_id' => (string) $comment->id,
            'commentable_type' => $type,
            'commentable_id' => $id,
        ];
        if ($type === 'reel') {
            $reel = DB::table('reels')->where('id', $id)->first(['id', 'caption']);
            $data['reel_id'] = $id;
            $caption = trim((string) ($reel->caption ?? ''));
            $data['content_title'] = $caption !== '' ? Str::limit($caption, 40) : 'your reel';

            return $data;
        }

        $episode = DB::table('episodes')->join('shows', 'shows.id', '=', 'episodes.show_id')->where('episodes.id', $id)->first(['episodes.id', 'episodes.show_id', 'episodes.title', 'shows.title as show_title']);
        $data['episode_id'] = $id;
        if ($episode) {
            $data['show_id'] = (string) $episode->show_id;
            $data['content_title'] = (string) ($episode->show_title ?: $episode->title ?: 'your podcast');
        } else {
            $data['content_title'] = 'your podcast';
        }

        return $data;
    }

    private function creatorUserId(string $type, string $id): ?string
    {
        if ($type === 'reel') {
            $userId = DB::table('reels')->join('creator_profiles', 'creator_profiles.id', '=', 'reels.creator_profile_id')->where('reels.id', $id)->value('creator_profiles.user_id');

            return is_string($userId) && $userId !== '' ? $userId : null;
        }

        $userId = DB::table('episodes')
            ->join('verified_show_claims', 'verified_show_claims.show_id', '=', 'episodes.show_id')
            ->join('show_claims', 'show_claims.id', '=', 'verified_show_claims.show_claim_id')
            ->join('creator_profiles', 'creator_profiles.id', '=', 'show_claims.creator_profile_id')
            ->where('episodes.id', $id)
            ->value('creator_profiles.user_id');

        return is_string($userId) && $userId !== '' ? $userId : null;
    }
}
