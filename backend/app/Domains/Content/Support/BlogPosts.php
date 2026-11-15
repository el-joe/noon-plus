<?php

namespace App\Domains\Content\Support;

use App\Domains\Content\Enums\PageStatus;
use App\Domains\Content\Models\BlogPost;
use App\Domains\Orders\Enums\ActorType;
use App\Shared\StateMachine\StateMachine;
use App\Shared\StateMachine\TransitionRefused;
use Carbon\CarbonImmutable;

/**
 * Blog post schedule (SRS §15, lifecycle catalogue §9.2): draft → published
 * now or scheduled (PublishScheduledBlogPostsJob every 5 min) → published →
 * archived; back to draft to edit offline.
 */
class BlogPosts
{
    public function __construct(private StateMachine $machine) {}

    public function publish(BlogPost $post, ActorType $actorType, ?int $userId): BlogPost
    {
        return $this->machine->move($post, PageStatus::Published, $actorType, $userId, null, ['published_at' => now(), 'publish_at' => null]);
    }

    public function schedule(BlogPost $post, CarbonImmutable $at, int $userId): BlogPost
    {
        if ($at->lessThanOrEqualTo(now())) {
            throw new TransitionRefused(__('Pick a time in the future.'));
        }

        return $this->machine->move($post, PageStatus::Scheduled, ActorType::Admin, $userId, null, ['publish_at' => $at]);
    }

    public function move(BlogPost $post, PageStatus $to, int $userId): BlogPost
    {
        return $this->machine->move($post, $to, ActorType::Admin, $userId, null, $to === PageStatus::Draft ? ['publish_at' => null] : []);
    }

    public function publishDue(): int
    {
        $published = 0;

        BlogPost::query()->where('status', PageStatus::Scheduled)->where('publish_at', '<=', now())->each(function (BlogPost $post) use (&$published): void {
            $this->publish($post, ActorType::System, null);
            $published++;
        });

        return $published;
    }
}
