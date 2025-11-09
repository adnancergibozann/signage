<?php

require_once __DIR__ . '/../config/database.php';

const PRAYER_LABELS = [
    'fajr' => 'İmsak',
    'sunrise' => 'Güneş',
    'dhuhr' => 'Öğle',
    'asr' => 'İkindi',
    'maghrib' => 'Akşam',
    'isha' => 'Yatsı',
    'jumuah' => 'Cuma Selası',
];

const ALADHAN_METHODS = [
    0 => 'JAF (default)',
    1 => 'İslam Dünyası Ligi',
    2 => 'Müslüman Birliği (Kuzey Amerika)',
    3 => 'Müslüman Dünya Birliği',
    4 => 'Umm Al-Qura',
    5 => 'Mısır',
    6 => 'Kuveyt',
    7 => 'Katar',
    8 => 'Singapur',
    9 => 'Ümmet İşleri Dairesi (Dubai)',
    10 => 'Kuveyt (Eski)',
    11 => 'İran',
    12 => 'Cezayir',
    13 => 'Diyanet İşleri Başkanlığı',
    14 => 'Fetva Kurulu (Tunus)',
    15 => 'General Authority of Islamic Affairs & Endowments (Abu Dabi)',
    16 => 'Moonsighting Committee',
    17 => 'Fransa',
    18 => 'Suudi Arabistan',
];

const ALLOWED_PRAYER_KEYS = ['fajr', 'dhuhr', 'asr', 'maghrib', 'isha', 'jumuah'];

function get_prayer_settings(?PDO $pdo = null): array
{
    $pdo = $pdo ?? get_pdo();
    $stmt = $pdo->query('SELECT * FROM prayer_settings WHERE id = 1');
    $settings = $stmt->fetch();

    if (!$settings) {
        $pdo->exec("INSERT IGNORE INTO prayer_settings (id) VALUES (1)");
        $stmt = $pdo->query('SELECT * FROM prayer_settings WHERE id = 1');
        $settings = $stmt->fetch();
    }

    if (!$settings) {
        throw new RuntimeException('Namaz ayarları yüklenemedi.');
    }

    $settings['calculation_method'] = (int) $settings['calculation_method'];
    $settings['jumuah_offset_minutes'] = (int) $settings['jumuah_offset_minutes'];
    $settings['auto_refresh_days'] = (int) $settings['auto_refresh_days'];

    return $settings;
}

function save_prayer_settings(array $input, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? get_pdo();
    $country = trim($input['country'] ?? '');
    $city = trim($input['city'] ?? '');
    $district = trim($input['district'] ?? '');
    $method = (int) ($input['calculation_method'] ?? 13);
    $madhab = $input['madhab'] ?? 'hanafi';
    $offset = (int) ($input['jumuah_offset_minutes'] ?? 45);
    $autoRefresh = max(1, min(60, (int) ($input['auto_refresh_days'] ?? 14)));

    if ($country === '' || $city === '') {
        throw new RuntimeException('Ülke ve il alanları zorunludur.');
    }

    if (!array_key_exists($method, ALADHAN_METHODS)) {
        throw new RuntimeException('Geçersiz hesaplama metodu seçildi.');
    }

    if (!in_array($madhab, ['hanafi', 'shafi'], true)) {
        throw new RuntimeException('Geçersiz mezhep seçimi.');
    }

    $offset = max(0, min(180, $offset));

    $stmt = $pdo->prepare('UPDATE prayer_settings SET country = :country, city = :city, district = :district, calculation_method = :method, madhab = :madhab, jumuah_offset_minutes = :offset, auto_refresh_days = :autoRefresh WHERE id = 1');
    $stmt->execute([
        ':country' => $country,
        ':city' => $city,
        ':district' => $district !== '' ? $district : null,
        ':method' => $method,
        ':madhab' => $madhab,
        ':offset' => $offset,
        ':autoRefresh' => $autoRefresh,
    ]);

    return get_prayer_settings($pdo);
}

function get_prayer_audio_profiles(?PDO $pdo = null): array
{
    $pdo = $pdo ?? get_pdo();
    $stmt = $pdo->query('SELECT * FROM prayer_audio_profiles ORDER BY FIELD(prayer_key, "fajr","dhuhr","asr","maghrib","isha","jumuah")');
    $profiles = $stmt->fetchAll();

    $results = [];
    foreach ($profiles as $profile) {
        $path = $profile['file_path'] ?? null;
        $url = null;
        if ($path) {
            $normalized = '/' . ltrim($path, '/');
            $absolute = realpath(__DIR__ . '/../' . ltrim($path, '/'));
            if ($absolute && is_file($absolute)) {
                $url = $normalized;
            } else {
                $path = null;
            }
        }

        $results[] = [
            'id' => (int) $profile['id'],
            'key' => $profile['prayer_key'],
            'label' => $profile['display_name'],
            'file_path' => $path,
            'file_url' => $url,
            'file_name' => $profile['file_name'],
            'file_mime' => $profile['file_mime'],
            'file_size' => $profile['file_size'] ? (int) $profile['file_size'] : null,
            'updated_at' => $profile['updated_at'],
        ];
    }

    return $results;
}

function update_prayer_audio(string $prayerKey, array $fileInfo, ?PDO $pdo = null): array
{
    if (!in_array($prayerKey, ALLOWED_PRAYER_KEYS, true)) {
        throw new RuntimeException('Geçersiz namaz anahtarı.');
    }

    if (!isset($fileInfo['tmp_name']) || !is_uploaded_file($fileInfo['tmp_name'])) {
        throw new RuntimeException('Ses dosyası yüklenemedi.');
    }

    $pdo = $pdo ?? get_pdo();
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($fileInfo['tmp_name']);
    $allowed = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/ogg' => 'ogg',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/webm' => 'webm',
    ];

    if (!array_key_exists($mime, $allowed)) {
        throw new RuntimeException('Desteklenmeyen ses biçimi.');
    }

    $extension = $allowed[$mime];
    $uploadDir = __DIR__ . '/../public/uploads/audio';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Ses yükleme dizini oluşturulamadı.');
    }

    $safeName = sprintf('%s-%s.%s', $prayerKey, date('YmdHis'), $extension);
    $relativePath = 'public/uploads/audio/' . $safeName;
    $targetPath = __DIR__ . '/../' . $relativePath;

    if (!move_uploaded_file($fileInfo['tmp_name'], $targetPath)) {
        throw new RuntimeException('Ses dosyası taşınamadı.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT file_path FROM prayer_audio_profiles WHERE prayer_key = :key FOR UPDATE');
        $stmt->execute([':key' => $prayerKey]);
        $current = $stmt->fetch();

        if ($current && !empty($current['file_path'])) {
            $oldPath = __DIR__ . '/../' . ltrim($current['file_path'], '/');
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        $stmt = $pdo->prepare('UPDATE prayer_audio_profiles SET file_path = :path, file_name = :name, file_mime = :mime, file_size = :size WHERE prayer_key = :key');
        $stmt->execute([
            ':path' => $relativePath,
            ':name' => $fileInfo['name'] ?? $safeName,
            ':mime' => $mime,
            ':size' => isset($fileInfo['size']) ? (int) $fileInfo['size'] : null,
            ':key' => $prayerKey,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return get_prayer_audio_profiles($pdo);
}

function delete_prayer_audio(string $prayerKey, ?PDO $pdo = null): array
{
    if (!in_array($prayerKey, ALLOWED_PRAYER_KEYS, true)) {
        throw new RuntimeException('Geçersiz namaz anahtarı.');
    }

    $pdo = $pdo ?? get_pdo();

    $stmt = $pdo->prepare('SELECT file_path FROM prayer_audio_profiles WHERE prayer_key = :key');
    $stmt->execute([':key' => $prayerKey]);
    $current = $stmt->fetch();

    if ($current && !empty($current['file_path'])) {
        $oldPath = __DIR__ . '/../' . ltrim($current['file_path'], '/');
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    $stmt = $pdo->prepare('UPDATE prayer_audio_profiles SET file_path = NULL, file_name = NULL, file_mime = NULL, file_size = NULL WHERE prayer_key = :key');
    $stmt->execute([':key' => $prayerKey]);

    return get_prayer_audio_profiles($pdo);
}

function refresh_prayer_times(?PDO $pdo = null, ?DateTimeImmutable $start = null, ?DateTimeImmutable $end = null): int
{
    $pdo = $pdo ?? get_pdo();
    $settings = get_prayer_settings($pdo);
    $timezone = new DateTimeZone($settings['timezone'] ?: 'UTC');

    $now = new DateTimeImmutable('now', $timezone);
    $start = $start ?? $now->setTime(0, 0);
    $end = $end ?? $start->add(new DateInterval('P' . max(7, (int) $settings['auto_refresh_days']) . 'D'));

    if ($end < $start) {
        $end = $start;
    }

    $calendar = fetch_prayer_calendar($settings, $start, $end);

    if (!$calendar) {
        throw new RuntimeException('API namaz vakti verisi sağlayamadı.');
    }

    $pdo->beginTransaction();
    try {
        $upsert = $pdo->prepare('INSERT INTO prayer_times (prayer_date, prayer_key, azan_at, source) VALUES (:date, :key, :time, :source) ON DUPLICATE KEY UPDATE azan_at = VALUES(azan_at), source = VALUES(source), fetched_at = NOW()');
        $timezoneUpdate = $pdo->prepare('UPDATE prayer_settings SET timezone = :tz WHERE id = 1');
        $timezoneUpdate->execute([':tz' => $calendar['timezone']]);

        $count = 0;
        foreach ($calendar['days'] as $date => $timings) {
            foreach ($timings as $key => $datetime) {
                if (!in_array($key, ALLOWED_PRAYER_KEYS, true)) {
                    continue;
                }

                $upsert->execute([
                    ':date' => $date,
                    ':key' => $key,
                    ':time' => $datetime->format('Y-m-d H:i:s'),
                    ':source' => 'aladhan',
                ]);
                $count++;
            }
        }

        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function fetch_prayer_calendar(array $settings, DateTimeImmutable $start, DateTimeImmutable $end): array
{
    $method = (int) ($settings['calculation_method'] ?? 13);
    $madhab = ($settings['madhab'] ?? 'hanafi') === 'hanafi' ? 1 : 0;
    $country = $settings['country'] ?? '';
    $city = $settings['city'] ?? '';
    $district = $settings['district'] ?? '';

    if ($country === '' || $city === '') {
        throw new RuntimeException('Namaz vakti için ülke ve il bilgisi gerekiyor.');
    }

    $periods = [];
    $timezone = null;

    $cursor = $start->setTime(0, 0);
    $lastMonth = null;

    while ($cursor <= $end) {
        $month = (int) $cursor->format('n');
        $year = (int) $cursor->format('Y');
        $identifier = sprintf('%04d-%02d', $year, $month);

        if (isset($periods[$identifier])) {
            $cursor = $cursor->modify('first day of next month');
            continue;
        }

        $query = [
            'city' => $city,
            'country' => $country,
            'method' => $method,
            'month' => $month,
            'year' => $year,
            'school' => $madhab,
        ];
        if ($district !== '') {
            $query['state'] = $district;
        }

        $url = 'https://api.aladhan.com/v1/calendarByCity?' . http_build_query($query);
        $response = http_request_json($url);

        if (!is_array($response) || ($response['code'] ?? 0) !== 200 || empty($response['data'])) {
            throw new RuntimeException('Namaz vakti servisine ulaşılamadı.');
        }

        if (!$timezone) {
            $timezone = $response['data'][0]['meta']['timezone'] ?? 'UTC';
        }

        foreach ($response['data'] as $day) {
            $dateString = $day['date']['gregorian']['date'] ?? null; // format: dd-mm-yyyy
            if (!$dateString) {
                continue;
            }

            $parts = explode('-', $dateString);
            if (count($parts) !== 3) {
                continue;
            }
            [$dayPart, $monthPart, $yearPart] = $parts;
            $dateIso = sprintf('%04d-%02d-%02d', (int) $yearPart, (int) $monthPart, (int) $dayPart);

            $currentDate = DateTimeImmutable::createFromFormat('Y-m-d', $dateIso, new DateTimeZone($timezone ?? 'UTC'));
            if (!$currentDate) {
                continue;
            }

            if ($currentDate < $start || $currentDate > $end) {
                continue;
            }

            $timings = $day['timings'] ?? [];
            foreach (['Fajr' => 'fajr', 'Dhuhr' => 'dhuhr', 'Asr' => 'asr', 'Maghrib' => 'maghrib', 'Isha' => 'isha'] as $apiKey => $prayerKey) {
                if (!isset($timings[$apiKey])) {
                    continue;
                }
                $timeString = preg_replace('/\s*\(.+\)$/', '', $timings[$apiKey]);
                $datetime = DateTimeImmutable::createFromFormat('Y-m-d H:i', $dateIso . ' ' . $timeString, new DateTimeZone($timezone ?? 'UTC'));
                if (!$datetime) {
                    continue;
                }
                $periods['days'][$dateIso][$prayerKey] = $datetime;
            }
        }

        $periods[$identifier] = true;
        $cursor = $cursor->modify('first day of next month');
    }

    if (!$timezone) {
        $timezone = $settings['timezone'] ?? 'UTC';
    }

    return [
        'timezone' => $timezone,
        'days' => $periods['days'] ?? [],
    ];
}

function http_request_json(string $url): array
{
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);

    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP isteği başarısız: ' . $error);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('HTTP yanıtı çözülemedi.');
    }

    $decoded['code'] = $decoded['code'] ?? $status;

    return $decoded;
}

function get_prayer_schedule(DateTimeImmutable $start, DateTimeImmutable $end, ?PDO $pdo = null): array
{
    $pdo = $pdo ?? get_pdo();
    $settings = get_prayer_settings($pdo);
    $timezone = new DateTimeZone($settings['timezone'] ?? 'UTC');

    $stmt = $pdo->prepare('SELECT prayer_date, prayer_key, azan_at FROM prayer_times WHERE prayer_date BETWEEN :start AND :end ORDER BY azan_at');
    $stmt->execute([
        ':start' => $start->format('Y-m-d'),
        ':end' => $end->format('Y-m-d'),
    ]);

    $results = [];
    while ($row = $stmt->fetch()) {
        $dateKey = $row['prayer_date'];
        $key = $row['prayer_key'];
        if (!in_array($key, ALLOWED_PRAYER_KEYS, true)) {
            continue;
        }

        $datetime = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['azan_at'], $timezone);
        if (!$datetime) {
            continue;
        }

        $results[$dateKey][] = [
            'key' => $key,
            'label' => PRAYER_LABELS[$key] ?? ucfirst($key),
            'datetime' => $datetime,
            'time_label' => $datetime->format('H:i'),
        ];
    }

    foreach ($results as &$items) {
        usort($items, static function (array $a, array $b): int {
            return $a['datetime'] <=> $b['datetime'];
        });
    }

    return $results;
}

function format_turkish_date(DateTimeImmutable $date, string $timezone): string
{
    if (class_exists('IntlDateFormatter')) {
        $formatter = new IntlDateFormatter('tr_TR', IntlDateFormatter::FULL, IntlDateFormatter::NONE, $timezone, IntlDateFormatter::GREGORIAN, 'd MMMM y EEEE');
        $formatted = $formatter->format($date);
        if (is_string($formatted)) {
            return $formatted;
        }
    }

    return $date->setTimezone(new DateTimeZone($timezone))->format('d.m.Y l');
}
