<?php

if (!function_exists('smtpSendMail')) {
    function smtpReadResponse($socket): string {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }
        return $response;
    }

    function smtpExpectCode(string $response, array $codes): void {
        $code = (int) substr(trim($response), 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new Exception('SMTP error: ' . trim($response));
        }
    }

    function smtpCommand($socket, string $command, array $codes): string {
        fwrite($socket, $command . "\r\n");
        $response = smtpReadResponse($socket);
        smtpExpectCode($response, $codes);
        return $response;
    }

    function smtpSendMail(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): bool {
        $host = getenv('SMTP_HOST') ?: '';
        $port = (int) (getenv('SMTP_PORT') ?: 587);
        $user = getenv('SMTP_USER') ?: '';
        $pass = getenv('SMTP_PASS') ?: '';
        $secure = strtolower(getenv('SMTP_SECURE') ?: 'tls');
        $fromEmail = getenv('SMTP_FROM_EMAIL') ?: $user;
        $fromName = getenv('SMTP_FROM_NAME') ?: 'Royal Mabati Factory';

        if (!$host || !$port || !$user || !$pass || !$fromEmail) {
            throw new Exception('SMTP is not configured. Set SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS and SMTP_FROM_EMAIL.');
        }

        $transport = $secure === 'ssl' ? 'ssl://' . $host : $host;
        $socket = stream_socket_client($transport . ':' . $port, $errno, $errstr, 30, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            throw new Exception("SMTP connection failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 30);

        try {
            smtpExpectCode(smtpReadResponse($socket), [220]);
            smtpCommand($socket, 'EHLO localhost', [250]);

            if ($secure === 'tls') {
                smtpCommand($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception('Failed to enable TLS for SMTP connection.');
                }
                smtpCommand($socket, 'EHLO localhost', [250]);
            }

            smtpCommand($socket, 'AUTH LOGIN', [334]);
            smtpCommand($socket, base64_encode($user), [334]);
            smtpCommand($socket, base64_encode($pass), [235]);

            smtpCommand($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]);
            smtpCommand($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            smtpCommand($socket, 'DATA', [354]);

            $boundary = 'bnd_' . bin2hex(random_bytes(12));
            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $safeFromName = addcslashes($fromName, '"');
            $safeToName = addcslashes($toName ?: $toEmail, '"');

            $headers = [
                'From: "' . $safeFromName . '" <' . $fromEmail . '>',
                'To: "' . $safeToName . '" <' . $toEmail . '>',
                'Subject: ' . $encodedSubject,
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            ];

            $message =
                implode("\r\n", $headers) . "\r\n\r\n" .
                '--' . $boundary . "\r\n" .
                "Content-Type: text/plain; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                $textBody . "\r\n\r\n" .
                '--' . $boundary . "\r\n" .
                "Content-Type: text/html; charset=UTF-8\r\n" .
                "Content-Transfer-Encoding: 8bit\r\n\r\n" .
                $htmlBody . "\r\n\r\n" .
                '--' . $boundary . "--\r\n.";

            fwrite($socket, $message . "\r\n");
            smtpExpectCode(smtpReadResponse($socket), [250]);
            smtpCommand($socket, 'QUIT', [221]);

            fclose($socket);
            return true;
        } catch (Throwable $e) {
            fclose($socket);
            throw $e;
        }
    }
}
