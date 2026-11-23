<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeder;
use App\Services\SettingsService;

/** Inserts default settings that do not exist yet. Never overwrites administrator changes. */
final class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        foreach (SettingsService::definitions() as $key => $def) {
            if ($this->db->scalar('SELECT 1 FROM system_settings WHERE setting_key = ?', [$key])) {
                continue;
            }
            $this->db->insert('system_settings', [
                'setting_key' => $key,
                'value' => SettingsService::serialize($def['default'], $def['type']),
                'type' => $def['type'],
                'group_name' => $def['group'],
                'description' => $def['label'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
