<?php

namespace App\Modules\Marketplace\Domain;

use App\Modules\Marketplace\Exceptions\ConnectorException;
use App\Modules\Marketplace\Exceptions\UnsupportedCapability;
use Throwable;

/**
 * Standard error codes of the Sync Center (docs/SYNC_CENTER_AUDIT.md §14). Every failure the sync machinery records
 * (webhook, stock push, price push, order poll, sync error) carries one, so they can be counted and filtered alike.
 */
final class SyncErrorCode
{
    public const TOKEN_EXPIRED = 'token_expired';

    public const PERMISSION_DENIED = 'permission_denied';

    public const RATE_LIMITED = 'rate_limited';

    public const API_UNAVAILABLE = 'api_unavailable';

    public const NETWORK_TIMEOUT = 'network_timeout';

    public const INVALID_DATA = 'invalid_data';

    public const NOT_FOUND = 'not_found';

    public const MISSING_MAPPING = 'missing_mapping';

    public const CONFLICT = 'conflict';

    public const DUPLICATE_EVENT = 'duplicate_event';

    /** The channel does not offer the operation (or it is not verified yet). */
    public const UNSUPPORTED = 'unsupported';

    public const INTERNAL_ERROR = 'internal_error';

    /** code => [label, what to do, retried automatically] */
    public const INFO = [
        self::TOKEN_EXPIRED => ['Khóa/Token hết hạn', 'Kết nối lại shop ở trang Kết nối sàn.', false],
        self::PERMISSION_DENIED => ['Không có quyền', 'Cấp lại quyền Đọc/Ghi cho khóa API hoặc ứng dụng.', false],
        self::RATE_LIMITED => ['Vượt giới hạn gọi API', 'Tự thử lại sau, không cần thao tác.', true],
        self::API_UNAVAILABLE => ['Sàn/website lỗi hoặc bảo trì', 'Tự thử lại sau, không cần thao tác.', true],
        self::NETWORK_TIMEOUT => ['Mất kết nối, quá thời gian', 'Tự thử lại sau; nếu kéo dài, kiểm tra website còn chạy.', true],
        self::INVALID_DATA => ['Dữ liệu không hợp lệ', 'Kiểm tra dữ liệu trên sàn/website rồi thử lại.', false],
        self::NOT_FOUND => ['Không tìm thấy trên sàn', 'Sản phẩm hoặc đơn đã bị xóa trên sàn/website.', false],
        self::MISSING_MAPPING => ['Chưa liên kết SKU', 'Liên kết sản phẩm sàn với SKU EzSell.', false],
        self::CONFLICT => ['Xung đột dữ liệu', 'Xem chi tiết và chọn cách xử lý.', false],
        self::DUPLICATE_EVENT => ['Sự kiện trùng', 'Đã bỏ qua, không cần thao tác.', false],
        self::UNSUPPORTED => ['Sàn chưa hỗ trợ', 'Thao tác này cần làm trực tiếp trên sàn.', false],
        self::INTERNAL_ERROR => ['Lỗi hệ thống EzSell', 'Thử lại; nếu lặp lại, báo bộ phận hỗ trợ.', true],
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_keys(self::INFO);
    }

    public static function label(?string $code): ?string
    {
        return $code !== null ? (self::INFO[$code][0] ?? $code) : null;
    }

    public static function fromException(Throwable $e): string
    {
        return match (true) {
            $e instanceof UnsupportedCapability => self::UNSUPPORTED,
            $e instanceof ConnectorException && $e->errorCode !== null => $e->errorCode,
            $e instanceof ConnectorException && $e->authExpired => self::TOKEN_EXPIRED,
            $e instanceof ConnectorException && $e->notFound => self::NOT_FOUND,
            $e instanceof ConnectorException && $e->temporary => self::API_UNAVAILABLE,
            $e instanceof ConnectorException => self::INVALID_DATA,
            default => self::INTERNAL_ERROR,
        };
    }
}
