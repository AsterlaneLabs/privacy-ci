<?php

use Illuminate\Database\Migrations\Migration;

/**
 * A schema declared as raw SQL rather than with the Blueprint builder, with a
 * non-conventional table name and primary key. Long-lived applications often
 * carry one of these, and it is usually their oldest and most important table.
 */
class CreateMembersTable extends Migration
{
    public function up()
    {
        DB::statement("CREATE TABLE `legacy_members` (
          `member_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `handle` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
          `email` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
          `password` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
          `first_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
          `last_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
          `state` enum('Active','Suspended','Closed','Pending Close','New') COLLATE utf8_unicode_ci NOT NULL DEFAULT 'New',
          `remember_token` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
          `joined_at` int(10) unsigned NOT NULL,
          `contactable` tinyint(4) NOT NULL DEFAULT '1',
          PRIMARY KEY (`member_id`),
          UNIQUE KEY `U_email` (`email`),
          KEY `member_id` (`member_id`,`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;");
    }

    public function down()
    {
        Schema::drop('legacy_members');
    }
}
