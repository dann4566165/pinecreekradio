<?php
declare(strict_types=1);

// The booking endpoint uses the same server-side mail environment as Songlist.
// Never put provider credentials or a recipient override in the browser.
const BOOKING_TYPES = ['Live Music', 'Live Band Music Bingo', 'Live Band Karaoke', 'Something Else / Not Sure Yet'];
const BOOKING_RECIPIENT = 'dannelder1@gmail.com';
const BOOKING_MAX_BODY = 48000;

function booking_field(array $data, string $key, int $limit, bool $required = false): string
{
    $value = $data[$key] ?? '';
    if (!is_string($value) || !preg_match('//u', $value)) {
        throw new InvalidArgumentException('Please check your form entries.');
    }
    $value = trim($value);
    if (($required && $value === '') || strlen($value) > $limit * 4
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)
        || preg_match_all('/./us', $value) > $limit) {
        throw new InvalidArgumentException('Please fill out the required fields and check the field lengths.');
    }
    return $value;
}

function booking_validate(array $data): array
{
    if (booking_field($data, 'website', 200) !== '') {
        throw new InvalidArgumentException('Please check your form entries.');
    }
    $fields = [
        'name' => booking_field($data, 'name', 100, true),
        'email' => booking_field($data, 'email', 254, true),
        'phone' => booking_field($data, 'phone', 40),
        'eventType' => booking_field($data, 'eventType', 100, true),
        'eventDate' => booking_field($data, 'eventDate', 10),
        'venue' => booking_field($data, 'venue', 200),
        'message' => booking_field($data, 'message', 3000, true),
    ];
    foreach (['name', 'phone', 'venue'] as $key) {
        if (preg_match('/[\r\n\t]/', $fields[$key])) {
            throw new InvalidArgumentException('Please check your form entries.');
        }
    }
    if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)
        || preg_match('/[\r\n]/', $fields['email'])) {
        throw new InvalidArgumentException('Please enter a valid email address.');
    }
    if (!in_array($fields['eventType'], BOOKING_TYPES, true)) {
        throw new InvalidArgumentException('Please choose an event type.');
    }
    if ($fields['eventDate'] !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $fields['eventDate']);
        if (!$date || $date->format('Y-m-d') !== $fields['eventDate']) {
            throw new InvalidArgumentException('Please enter a valid event date.');
        }
    }
    return $fields;
}

function booking_config(): array
{
    $provider = strtolower(trim((string)(getenv('BOOKING_EMAIL_PROVIDER') ?: getenv('AUTH_EMAIL_PROVIDER') ?: 'resend')));
    $from = trim((string)(getenv('BOOKING_EMAIL_FROM') ?: getenv('AUTH_EMAIL_FROM') ?: 'songlist@pinecreekradio.com'));
    $token = trim((string)getenv($provider === 'postmark' ? 'POSTMARK_SERVER_TOKEN' : 'RESEND_API_KEY'));
    if (!in_array($provider, ['resend', 'postmark'], true) || $token === ''
        || !filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $from)
        || preg_match('/[\r\n]/', $token)
        || (!function_exists('curl_init') && !filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN))) {
        throw new RuntimeException('Booking email is not configured.');
    }
    return ['provider' => $provider, 'from' => "Pine Creek Radio <{$from}>", 'token' => $token];
}

function booking_email(array $fields, array $config): array
{
    $line = static function (string $key) use ($fields): string {
        return $fields[$key] === '' ? 'Not provided' : $fields[$key];
    };
    $subject = 'Pine Creek Radio booking inquiry: ' . $fields['eventType'];
    $body = sprintf(
        "New booking inquiry from pinecreekradio.com\n\nName: %s\nEmail: %s\nPhone: %s\n"
        . "Interested in: %s\nEvent date: %s\nVenue / location: %s\n\nMessage:\n%s\n",
        $line('name'), $line('email'), $line('phone'), $line('eventType'),
        $line('eventDate'), $line('venue'), $line('message')
    );
    if ($config['provider'] === 'postmark') {
        return [
            'url' => 'https://api.postmarkapp.com/email',
            'headers' => ['X-Postmark-Server-Token: ' . $config['token']],
            'payload' => [
                'From' => $config['from'], 'To' => BOOKING_RECIPIENT, 'ReplyTo' => $fields['email'],
                'Subject' => $subject, 'TextBody' => $body,
                'MessageStream' => getenv('POSTMARK_MESSAGE_STREAM') ?: 'outbound',
            ],
        ];
    }
    return [
        'url' => 'https://api.resend.com/emails',
        'headers' => ['Authorization: Bearer ' . $config['token']],
        'payload' => [
            'from' => $config['from'], 'to' => [BOOKING_RECIPIENT], 'reply_to' => $fields['email'],
            'subject' => $subject, 'text' => $body,
        ],
    ];
}

// Rate limit valid email attempts across PHP workers with a small private,
// locked file outside the document root. Do not store names, messages or emails.
function booking_rate_limit(string $ip, ?string $directory = null, ?int $now = null): bool
{
    $directory = $directory ?? sys_get_temp_dir() . '/pinecreekradio-booking-rate-v1';
    $now = $now ?? time();
    if (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory)) {
        throw new RuntimeException('Cannot initialize booking rate limit.');
    }
    if (is_link($directory) || !is_writable($directory) || (fileperms($directory) & 0077) !== 0) {
        throw new RuntimeException('Unsafe booking rate limit directory.');
    }
    $path = $directory . '/attempts.json';
    if (is_link($path)) {
        throw new RuntimeException('Unsafe booking rate limit file.');
    }
    $file = @fopen($path, 'c+');
    if (!$file) {
        throw new RuntimeException('Cannot open booking rate limit.');
    }
    try {
        if (!flock($file, LOCK_EX)) {
            throw new RuntimeException('Cannot lock booking rate limit.');
        }
        $raw = stream_get_contents($file, 32768);
        if ($raw === false || !feof($file)) {
            throw new RuntimeException('Cannot read booking rate limit.');
        }
        $records = $raw === '' ? [] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($records)) {
            throw new RuntimeException('Invalid booking rate limit state.');
        }
        $hash = hash('sha256', $ip);
        $records = array_values(array_filter($records, static function ($row) use ($now): bool {
            return is_array($row) && isset($row['time'], $row['ip']) && $row['time'] > $now - 900;
        }));
        $ipCount = count(array_filter($records, static function ($row) use ($hash): bool {
            return $row['ip'] === $hash;
        }));
        if ($ipCount >= 5 || count($records) >= 50) {
            return false;
        }
        $records[] = ['time' => $now, 'ip' => $hash];
        $encoded = json_encode($records, JSON_THROW_ON_ERROR);
        if (!rewind($file) || !ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || !fflush($file)) {
            throw new RuntimeException('Cannot update booking rate limit.');
        }
        return true;
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
}

function booking_send(array $email): void
{
    $json = json_encode($email['payload'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    $headers = array_merge(['Accept: application/json', 'Content-Type: application/json'], $email['headers']);
    if (function_exists('curl_init')) {
        $curl = curl_init($email['url']);
        if (!$curl) {
            throw new RuntimeException('Cannot initialize email request.');
        }
        try {
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $response = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
        } finally {
            curl_close($curl);
        }
    } else {
        $context = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $json,
            'timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $response = @file_get_contents($email['url'], false, $context);
        $status = 0;
        if (isset($http_response_header[0]) && preg_match('/^HTTP\/\S+ (\d{3})/', $http_response_header[0], $match)) {
            $status = (int)$match[1];
        }
    }
    $result = is_string($response) ? json_decode($response, true) : null;
    $accepted = is_array($result) && (
        $email['url'] === 'https://api.resend.com/emails'
            ? !empty($result['id'])
            : ($result['ErrorCode'] ?? null) === 0 && !empty($result['MessageID'])
    );
    if ($status < 200 || $status >= 300 || !$accepted) {
        // No provider response body or credentials in application logs.
        throw new RuntimeException('Email provider did not accept inquiry (HTTP ' . $status . ').');
    }
}

function booking_respond(int $status, string $message, bool $json = true): void
{
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $ok = $status >= 200 && $status < 300;
    if ($json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        $safe = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Pine Creek Radio Booking</title><body style="background:#0a1428;color:white;font:18px/1.6 system-ui;padding:2rem">'
            . '<h1>Pine Creek Radio</h1><p>' . $safe . '</p><a style="color:#ffd166" href="/#contact">Back to Pine Creek Radio</a></body></html>';
    }
}

function booking_main(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if ($method === 'GET') {
        // Deployment readiness check: do not expose mail details or send email.
        try {
            booking_config();
            booking_respond(200, 'Booking form available.');
        } catch (Throwable $error) {
            booking_respond(503, 'Booking form temporarily unavailable.');
        }
        return;
    }
    if ($method !== 'POST') {
        header('Allow: GET, POST');
        booking_respond(405, 'Method not allowed.');
        return;
    }
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    $json = $contentType === 'application/json';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (($origin !== '' && !in_array($origin, ['https://pinecreekradio.com', 'https://www.pinecreekradio.com'], true))
        || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
        booking_respond(403, 'Please submit the form from Pine Creek Radio.', $json);
        return;
    }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > BOOKING_MAX_BODY) {
        booking_respond(413, 'Your inquiry is too long.', $json);
        return;
    }
    try {
        if ($json) {
            $body = file_get_contents('php://input', false, null, 0, BOOKING_MAX_BODY + 1);
            if ($body === false || strlen($body) > BOOKING_MAX_BODY) {
                booking_respond(413, 'Your inquiry is too long.');
                return;
            }
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } elseif ($contentType === 'application/x-www-form-urlencoded') {
            $data = $_POST;
        } else {
            booking_respond(415, 'Please use the booking form to submit your inquiry.');
            return;
        }
        if (!is_array($data)) {
            throw new InvalidArgumentException('Please check your form entries.');
        }
        $fields = booking_validate($data);
        $config = booking_config();
        // Only trust the direct remote address (or web server's configured remote-IP module).
        if (!booking_rate_limit($_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
            header('Retry-After: 900');
            booking_respond(429, 'Too many inquiries. Please wait a few minutes and try again.', $json);
            return;
        }
        booking_send(booking_email($fields, $config));
        booking_respond(200, "Thanks for reaching out! Your inquiry was sent. We'll be in touch soon.", $json);
    } catch (InvalidArgumentException $error) {
        booking_respond(400, $error->getMessage(), $json);
    } catch (JsonException $error) {
        booking_respond(400, 'Please check your form entries and try again.', $json);
    } catch (Throwable $error) {
        error_log('Pine Creek Radio booking delivery failed: ' . get_class($error));
        booking_respond(503, 'Unable to send your inquiry right now. Please try again in a moment.', $json);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    booking_main();
}
