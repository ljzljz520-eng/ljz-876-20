<?php

namespace App\Support;

use App\Models\ExamAnomaly;
use App\Models\ExamSession;
use App\Models\InvigilationLog;
use Illuminate\Support\Str;

class SeatService
{
    /**
     * 生成座位二维码令牌：SEAT-{场次ID}-{随机串}
     */
    public static function makeToken(int $sessionId): string
    {
        return 'SEAT-' . $sessionId . '-' . strtoupper(Str::random(16));
    }

    /**
     * 从扫码内容中解析座位令牌。兼容直接令牌或 URL 形式：
     * /invigilation/scan/SEAT-1-XXXX
     */
    public static function parseToken(string $code): ?string
    {
        $code = trim($code);
        if (preg_match('/(SEAT-\d+-[A-Z0-9-]{4,})/i', $code, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    /**
     * 判断 IP 是否属于 CIDR 网段（支持 IPv4）。
     */
    public static function ipInRange(string $ip, ?string $range): bool
    {
        if (!$range) {
            return true;
        }

        $range = trim($range);
        if (!str_contains($range, '/')) {
            return $ip === $range;
        }

        [$subnet, $bits] = explode('/', $range);
        $bits = (int) $bits;

        if ($bits <= 0) {
            return true;
        }
        if ($bits > 32) {
            return false;
        }

        // 用 inet_pton 拿到 4 字节二进制，按无符号字节逐位比较，
        // 避免 ip2long/移位在 32 位与 64 位 PHP 上的符号差异
        $ipBytes = @inet_pton($ip);
        $subnetBytes = @inet_pton($subnet);
        if ($ipBytes === false || $subnetBytes === false || strlen($ipBytes) !== 4 || strlen($subnetBytes) !== 4) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remainBits = $bits % 8;

        for ($i = 0; $i < $fullBytes; $i++) {
            if ($ipBytes[$i] !== $subnetBytes[$i]) {
                return false;
            }
        }

        if ($remainBits > 0) {
            $byteMask = chr((0xFF << (8 - $remainBits)) & 0xFF);
            if ((chr(ord($ipBytes[$fullBytes]) & ord($byteMask))) !== (chr(ord($subnetBytes[$fullBytes]) & ord($byteMask)))) {
                return false;
            }
        }

        return true;
    }

    /**
     * 浏览器指纹：UA + Accept-Language 的短哈希
     */
    public static function uaFingerprint(?string $ua, ?string $lang): string
    {
        return substr(hash('sha256', ($ua ?? '') . '|' . ($lang ?? '')), 0, 32);
    }

    /**
     * 写入监考日志
     */
    public static function log(int $sessionId, int $operatorId, string $action, string $detail, ?string $seatNo = null, ?int $userId = null): InvigilationLog
    {
        return InvigilationLog::create([
            'exam_session_id' => $sessionId,
            'operator_id' => $operatorId,
            'action' => $action,
            'detail' => $detail,
            'seat_no' => $seatNo,
            'user_id' => $userId,
        ]);
    }

    /**
     * 写入异常记录
     */
    public static function anomaly(
        ?int $sessionId,
        ?int $userId,
        string $type,
        string $detail,
        string $severity = ExamAnomaly::SEVERITY_WARNING,
        ?int $seatAssignmentId = null,
        ?int $recordId = null
    ): ExamAnomaly {
        return ExamAnomaly::create([
            'exam_session_id' => $sessionId,
            'exam_seat_assignment_id' => $seatAssignmentId,
            'exam_record_id' => $recordId,
            'user_id' => $userId,
            'type' => $type,
            'detail' => $detail,
            'severity' => $severity,
        ]);
    }
}
