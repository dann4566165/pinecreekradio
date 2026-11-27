<?php
declare(strict_types=1);
require __DIR__ . '/../contact.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function invalid(array $data): void
{
    try {
        booking_validate($data);
    } catch (InvalidArgumentException $error) {
        return;
    }
    throw new RuntimeException('Invalid inquiry was accepted.');
}

$valid = [
    'name' => 'Example Booker', 'email' => 'booker@example.com', 'eventType' => 'Live Band Music Bingo',
    'message' => "Looking for live music.\nCan you send pricing?", 'venue' => 'Example Venue',
    'eventDate' => '2027-01-23', 'website' => '',
];
$fields = booking_validate($valid);
check($fields['phone'] === '', 'Phone should be optional.');
check(booking_validate(array_replace($valid, ['eventDate' => '', 'venue' => '']))['venue'] === '', 'Date and venue should be optional.');
foreach ([
    ['name' => ' '], ['email' => 'invalid'], ['email' => "a@example.com\nCC: b@example.net"],
    ['eventType' => 'unknown'], ['eventDate' => '2027-02-30'], ['message' => ''],
    ['message' => str_repeat('x', 3001)], ['website' => 'spam'], ['phone' => ['not a string']],
    ['name' => "Name\nEmail: fake@example.com"],
] as $change) {
    invalid(array_replace($valid, $change));
}

$config = ['provider' => 'resend', 'token' => 'test-not-a-real-token', 'from' => 'Pine Creek Radio <test@example.com>'];
$payload = booking_email($fields, $config);
check($payload['payload']['to'] === [BOOKING_RECIPIENT], 'Recipient must be fixed on the server.');
check($payload['payload']['reply_to'] === 'booker@example.com', 'Reply-To should be the booker.');
check(strpos($payload['payload']['text'], $fields['message']) !== false, 'Message should be included verbatim.');
check(strpos($payload['payload']['text'], 'Phone: Not provided') !== false, 'Optional fields should be labeled.');
$payload = booking_email($fields, array_replace($config, ['provider' => 'postmark']));
check($payload['payload']['To'] === BOOKING_RECIPIENT && $payload['payload']['ReplyTo'] === 'booker@example.com', 'Postmark recipient and Reply-To.');

$directory = sys_get_temp_dir() . '/pcr-booking-test-' . bin2hex(random_bytes(12));
try {
    for ($i = 0; $i < 5; $i++) {
        check(booking_rate_limit('192.0.2.1', $directory, 1000), 'First five attempts should pass.');
    }
    check(!booking_rate_limit('192.0.2.1', $directory, 1000), 'Sixth attempt should be limited.');
    check(booking_rate_limit('192.0.2.2', $directory, 1000), 'Independent IP should pass.');
    for ($i = 3; $i <= 46; $i++) {
        check(booking_rate_limit('192.0.2.' . $i, $directory, 1000), 'Below global limit should pass.');
    }
    check(!booking_rate_limit('192.0.2.99', $directory, 1000), 'Global limit should apply.');
    check(booking_rate_limit('192.0.2.1', $directory, 1901), 'Old attempts should expire.');
} finally {
    @unlink($directory . '/attempts.json');
    @rmdir($directory);
}
echo "Booking form tests passed (no email sent).\n";
