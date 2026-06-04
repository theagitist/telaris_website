<?php
declare(strict_types=1);

/**
 * Mail sender wrapper around PHPMailer.
 *
 * One public function: pluriverse_send_mail($to, $subject, $body). Plaintext
 * only; the audience is operators (technical) and HTML emails would add
 * deliverability complexity without payoff.
 *
 * Configuration: MAIL_SMTP_HOST / PORT / USER / PASS / SECURE constants
 * (Mailgun on this host), MAIL_FROM_ADDRESS / NAME (defaults to
 * no-reply@telaris.ca / Pluriverse). Constants live in config.php; they're
 * read at call time so a per-request override (e.g. in tests) takes effect.
 *
 * Returns true on send; throws RuntimeException on any failure (caller
 * decides whether to surface to user, retry, log, or all three). Internally
 * also logs to error_log for any failure path so production has a trail.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function pluriverse_send_mail(string $to, string $subject, string $body): bool {
    // Dry-run short-circuit: when MAIL_DRY_RUN is defined and truthy (the test
    // bootstrap defines it; production never does), log a redacted line and
    // return without contacting any relay. Mirrors the instance-side guard so
    // the suite can exercise mail-sending paths without delivering real mail.
    if (defined('MAIL_DRY_RUN') && MAIL_DRY_RUN) {
        error_log('pluriverse_send_mail: MAIL_DRY_RUN active; not sending. subject=' . $subject);
        return true;
    }
    foreach (['MAIL_SMTP_HOST', 'MAIL_SMTP_PORT', 'MAIL_SMTP_USER', 'MAIL_SMTP_PASS', 'MAIL_SMTP_SECURE', 'MAIL_FROM_ADDRESS'] as $required) {
        if (!defined($required)) {
            throw new RuntimeException("pluriverse_send_mail: required constant {$required} is not defined in config.php");
        }
    }
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("pluriverse_send_mail: invalid recipient address");
    }

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = (string)MAIL_SMTP_HOST;
        $mail->Port = (int)MAIL_SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = (string)MAIL_SMTP_USER;
        $mail->Password = (string)MAIL_SMTP_PASS;
        $mail->SMTPSecure = (string)MAIL_SMTP_SECURE;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(
            (string)MAIL_FROM_ADDRESS,
            defined('MAIL_FROM_NAME') ? (string)MAIL_FROM_NAME : 'Pluriverse'
        );
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $body;
        $mail->isHTML(false);
        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('pluriverse_send_mail FAILED → ' . $to . ': ' . $mail->ErrorInfo);
        throw new RuntimeException('pluriverse_send_mail: ' . $mail->ErrorInfo, 0, $e);
    }
}

/**
 * Test that SMTP is configured + reachable WITHOUT sending a real message.
 * Returns ['ok' => bool, 'detail' => string]. Useful for bin/setup-app
 * smoke checks.
 */
function pluriverse_mail_connection_check(): array {
    foreach (['MAIL_SMTP_HOST', 'MAIL_SMTP_PORT', 'MAIL_SMTP_USER', 'MAIL_SMTP_PASS', 'MAIL_SMTP_SECURE'] as $required) {
        if (!defined($required) || (string)constant($required) === '') {
            return ['ok' => false, 'detail' => "{$required} not defined or empty in config.php"];
        }
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = (string)MAIL_SMTP_HOST;
        $mail->Port = (int)MAIL_SMTP_PORT;
        $mail->SMTPAuth = true;
        $mail->Username = (string)MAIL_SMTP_USER;
        $mail->Password = (string)MAIL_SMTP_PASS;
        $mail->SMTPSecure = (string)MAIL_SMTP_SECURE;
        $mail->Timeout = 5;
        if (!$mail->smtpConnect()) {
            return ['ok' => false, 'detail' => 'SMTP connect failed'];
        }
        $mail->smtpClose();
        return ['ok' => true, 'detail' => MAIL_SMTP_HOST . ':' . MAIL_SMTP_PORT . ' reachable + auth ok'];
    } catch (PHPMailerException $e) {
        return ['ok' => false, 'detail' => $mail->ErrorInfo];
    }
}
