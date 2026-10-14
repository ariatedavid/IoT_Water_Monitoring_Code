<?php

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../PHPMailer-7.1.1/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-7.1.1/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-7.1.1/src/SMTP.php';

function sendLeakAlertEmail($flowRate, $threshold, &$error = '') {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'dladea024@gmail.com';
        $mail->Password = 'drtlcufkikiikzaz';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port = 465;

        $mail->setFrom('dladea024@gmail.com', 'IoT Water Monitor');
        $mail->addAddress('mathewmileschavez@gmail.com');
        $mail->addAddress('ariatedavidangelo@gmail.com');
        $mail->addAddress('dladea001@gmail.com');
        $mail->addAddress('darylluis.adea@letran.edu.ph');
        $mail->addAddress('mathewmiles.chavez@letran.edu.ph');
        $mail->addAddress('davidangelo.ariate@letran.edu.ph');
        $mail->isHTML(true);
        $formattedFlowRate = number_format($flowRate, 2);
        $logoPath = __DIR__ . '/../images/logo.jpg';
        $mail->addEmbeddedImage($logoPath, 'water-monitor-logo', 'logo.jpg');
        $mail->Subject = 'You Have a Leakage!';
        $mail->Body = '<p>Leak Detected! Flow Rate Detected: <strong>'
            . $formattedFlowRate . ' L/min</strong></p>'
            . '<br><hr>'
            . '<p>Knights Cafe<br>'
            . 'IT4A<br>'
            . 'CEIT Department<br>'
            . 'Colegio de San Juan de Letran<br>'
            . '151 Muralla St, Intramuros, Manila</p>'
            . '<img src="cid:water-monitor-logo" alt="Knights Cafe logo" width="120">';
        $mail->AltBody = 'Leak Detected! Flow Rate Detected: '
            . $formattedFlowRate . " L/min\n\n"
            . "Knights Cafe\n"
            . "IT4A\n"
            . "CEIT Department\n"
            . "Colegio de San Juan de Letran\n"
            . "151 Muralla St, Intramuros, Manila";
        $mail->send();
        return true;
    } catch (Exception $exception) {
        $error = $mail->ErrorInfo;
        return false;
    }
}

function processAutomaticLeakAlert($conn, $flowRate) {
    if (!getLeakDetectionEnabled($conn)) {
        setSetting($conn, 'leak_alert_active', '0');
        setSetting($conn, 'last_leak_alert_error', '');
        return false;
    }

    $threshold = getLeakThreshold($conn);
    $isLeak = getFlowStatus($flowRate, $threshold)['status'] === 'LEAK DETECTED';

    if (!$isLeak) {
        setSetting($conn, 'leak_alert_active', '0');
        return false;
    }

    if (getSetting($conn, 'leak_alert_active', '0') === '1') {
        return false;
    }

    $error = '';
    if (!sendLeakAlertEmail($flowRate, $threshold, $error)) {
        setSetting($conn, 'last_leak_alert_error', $error);
        return false;
    }

    setSetting($conn, 'leak_alert_active', '1');
    setSetting($conn, 'last_leak_alert_error', '');
    return true;
}