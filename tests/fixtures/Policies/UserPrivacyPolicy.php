<?php

namespace App\Privacy;

use App\Models\Comment;
use App\Models\Order;
use App\Models\User;
use PrivacyCI\Policy\PrivacyPolicy;

class UserPrivacyPolicy extends PrivacyPolicy
{
    public function configure(): void
    {
        $this->subject(User::class);

        $this->delete(User::class);
        $this->anonymize(Comment::class, ['user_id' => null, 'author_ip' => null]);
        $this->retain(Order::class)->reason('statutory accounting retention, 7y');

        // sessions has a migration but no Eloquent model, so the generator has
        // to fall back to the query builder.
        $this->anonymize('sessions', ['user_id' => null, 'ip_address' => null]);

        // A delete on a table linked by a key, so the generator has to chunk it.
        $this->delete('audit_entries');

        $this->deleteRedis('profile:{id}');
        $this->deleteStorage('avatars/{id}.jpg', disk: 's3');

        // The subject is the document in their own index, and a field on
        // documents keyed by something else in the comment index.
        $this->deleteSearch('users');
        $this->deleteSearch('comments_index', by: 'user_id');
    }
}
