<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Schema\Schema;

abstract class Migration
{
    abstract public function up(Schema $schema, Database $db): void;

    abstract public function down(Schema $schema, Database $db): void;
}
