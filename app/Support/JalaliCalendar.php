<?php

namespace App\Support;

use Carbon\Carbon;

class JalaliCalendar
{
    /**
     * Current Jalali month bounds in the app timezone.
     *
     * @return array{from: Carbon, to: Carbon, key: string}
     */
    public static function currentMonth(?Carbon $now = null): array
    {
        $timezone = self::timezone();
        $now = ($now ?? Carbon::now($timezone))->copy()->timezone($timezone);
        [$jy, $jm] = self::toJalali($now);

        $from = self::fromJalali($jy, $jm, 1, $timezone)->startOfDay();

        if ($jm === 12) {
            $nextYear = $jy + 1;
            $nextMonth = 1;
        } else {
            $nextYear = $jy;
            $nextMonth = $jm + 1;
        }

        $to = self::fromJalali($nextYear, $nextMonth, 1, $timezone)->startOfDay()->subSecond();

        return [
            'from' => $from,
            'to' => $to,
            'key' => self::formatKey($jy, $jm),
        ];
    }

    public static function monthKey(Carbon $at): string
    {
        $local = $at->copy()->timezone(self::timezone());
        [$jy, $jm] = self::toJalali($local);

        return self::formatKey($jy, $jm);
    }

    public static function timezone(): string
    {
        return (string) config('app.timezone', 'Asia/Tehran');
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function toJalali(Carbon $date): array
    {
        return self::gregorianToJalali(
            (int) $date->year,
            (int) $date->month,
            (int) $date->day,
        );
    }

    public static function fromJalali(int $jy, int $jm, int $jd, ?string $timezone = null): Carbon
    {
        [$gy, $gm, $gd] = self::jalaliToGregorian($jy, $jm, $jd);

        return Carbon::create($gy, $gm, $gd, 0, 0, 0, $timezone ?? self::timezone());
    }

    private static function formatKey(int $jy, int $jm): string
    {
        return sprintf('%04d-%02d', $jy, $jm);
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $gDaysInMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) + $gd + $gDaysInMonth[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd
            + ($jm < 7 ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;

            if ($days >= 365) {
                $days++;
            }
        }

        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $gd = $days + 1;
        $salA = [0, 31, (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0)) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;

        for ($gm = 1; $gm <= 12 && $gd > $salA[$gm]; $gm++) {
            $gd -= $salA[$gm];
        }

        return [$gy, $gm, $gd];
    }
}
