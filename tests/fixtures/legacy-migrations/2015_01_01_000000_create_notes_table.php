<?php

use Illuminate\Database\Migrations\Migration;

class CreateLegacyNotesTable extends Migration
{
    public function up()
    {
        DB::statement("CREATE TABLE IF NOT EXISTS `legacy_notes` (
          `note_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
          `member_id` int(10) unsigned NOT NULL,
          `body` text COLLATE utf8_unicode_ci,
          `ip_address` varchar(45) DEFAULT NULL,
          PRIMARY KEY (`note_id`),
          CONSTRAINT `fk_notes_member` FOREIGN KEY (`member_id`) REFERENCES `legacy_members` (`member_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB;");
    }

    public function down()
    {
        DB::statement('DROP TABLE IF EXISTS `legacy_notes`;');
    }
}
