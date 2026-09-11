<?php
// api/src/Controllers/SettingsController.php
declare(strict_types=1);

class SettingsController
{
    /**
     * GET /settings
     * Returns the authenticated user's settings.
     */
    public static function index(array $params, int $userId): never
    {
        $settings = self::_getOrCreate($userId);
        Response::json(['media_cache_days' => (int) $settings['media_cache_days']]);
    }

    /**
     * PUT /settings
     * Body: { media_cache_days: int }
     */
    public static function update(array $params, int $userId): never
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $days = (int) ($body['media_cache_days'] ?? 0);

        if ($days < 1 || $days > 365) {
            Response::error('media_cache_days must be between 1 and 365', 422);
        }

        Database::connection()->prepare(
            'INSERT INTO user_settings (user_id, media_cache_days)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE media_cache_days = ?'
        )->execute([$userId, $days, $days]);

        Response::json(['media_cache_days' => $days]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function _getOrCreate(int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT media_cache_days FROM user_settings WHERE user_id = ?'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) {
            // Create default row
            Database::connection()->prepare(
                'INSERT IGNORE INTO user_settings (user_id, media_cache_days) VALUES (?, 30)'
            )->execute([$userId]);
            return ['media_cache_days' => 30];
        }

        return $row;
    }
}
