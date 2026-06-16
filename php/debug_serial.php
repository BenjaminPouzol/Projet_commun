<?php
// Script de diagnostic — affiche les valeurs brutes reçues sur le port série
// Usage : php php/debug_serial.php
// Ctrl+C pour arrêter

declare(strict_types=1);

$port = 'COM3';
shell_exec("mode {$port}: BAUD=9600 PARITY=N DATA=8 STOP=1 XON=OFF 2>NUL");
$fd = @fopen('\\\\.\\'  . $port, 'r+b');

if ($fd === false) {
    echo "ERREUR : impossible d'ouvrir $port\n";
    exit(1);
}
stream_set_blocking($fd, false);

echo "Lecture sur $port — Approche-toi / éloigne-toi du capteur (Ctrl+C pour arrêter)\n";
echo str_repeat('-', 50) . "\n";

$ligne = '';
$nb    = 0;

while (true) {
    $octet = @fread($fd, 1);
    if ($octet === false || $octet === '') { usleep(10_000); continue; }

    if ($octet === "\n") {
        $trame = trim($ligne);
        if ($trame !== '') {
            $nb++;
            // Extraire la valeur si format PROXIMITE:XXXX
            if (preg_match('/^PROXIMITE:(\d+)$/', $trame, $m)) {
                $val  = (int) $m[1];
                $bar  = str_repeat('█', (int)($val / 4095 * 40));
                $etat = $val >= 500 ? 'OCCUPEE' : ($val >= 100 ? 'AMBIGU ' : 'LIBRE  ');
                printf("[%s] #%04d  %s  val=%-4d  %s\n",
                    date('H:i:s'), $nb, $etat, $val, $bar);
            } else {
                printf("[%s] #%04d  TRAME : %s\n", date('H:i:s'), $nb, $trame);
            }
        }
        $ligne = '';
    } else {
        $ligne .= $octet;
        if (strlen($ligne) > 64) $ligne = '';
    }
}
