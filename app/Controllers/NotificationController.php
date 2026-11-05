<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Dates;
use PDO;

final class NotificationController
{
    private const PER_PAGE = 20;

    /** GET /api/notifications?page=1 */
    public function index(Request $r): void
    {
        $userId = Auth::id();
        $page = max(1, (int) ($r->query('page') ?? 1));

        $total = (int) $this->scalar('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$userId]);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        Response::success([
            'items'        => self::items($userId, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'unread_count' => self::unread($userId),
            'pagination'   => ['page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total, 'total_pages' => $pages],
        ]);
    }

    /** POST /api/notifications/read   { "ids": [1,2] } or { "all": true } */
    public function markRead(Request $r): void
    {
        $userId = Auth::id();
        $db = Database::connection();

        if ($r->input('all') === true) {
            $db->prepare('UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL')->execute([$userId]);
        } else {
            $ids = $r->input('ids');
            if (!is_array($ids) || $ids === [] || count($ids) > 100) {
                throw new HttpException(422, 'Send "ids" (a list of notification ids) or "all": true.', [], 'VALIDATION_FAILED');
            }
            $ids = array_values(array_filter(array_map('intval', $ids), fn ($id) => $id > 0));
            if ($ids === []) {
                throw new HttpException(422, 'No valid notification ids were sent.', [], 'VALIDATION_FAILED');
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            // "and user_id = ?" means you can only mark your own notifications, whatever ids are sent.
            $db->prepare("UPDATE notifications SET read_at = UTC_TIMESTAMP() WHERE user_id = ? AND read_at IS NULL AND id IN ({$in})")
                ->execute(array_merge([$userId], $ids));
        }
        Response::success(['unread_count' => self::unread($userId)]);
    }

    /** Used by the dashboard. */
    public static function summary(int $userId, int $limit): array
    {
        return ['unread_count' => self::unread($userId), 'items' => self::items($userId, $limit, 0)];
    }

    private static function items(int $userId, int $limit, int $offset): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT n.id, n.type, n.title, n.message, n.read_at, n.created_at, t.reference
             FROM notifications n LEFT JOIN transactions t ON t.id = n.transaction_id
             WHERE n.user_id = ? ORDER BY n.created_at DESC, n.id DESC LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return array_map(fn ($n) => [
            'id'                    => (int) $n['id'],
            'type'                  => $n['type'],
            'title'                 => $n['title'],
            'message'               => $n['message'],
            'read'                  => $n['read_at'] !== null,
            'transaction_reference' => $n['reference'],
            'created_at'            => Dates::iso($n['created_at']),
        ], $stmt->fetchAll());
    }

    private static function unread(int $userId): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$userId]);
        return (int) $stmt->fetchColumn();
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
