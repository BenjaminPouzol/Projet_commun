<?php
/**
 * Fonctions utilitaires — Projet IoT ISEP
 * (fonctions multi-capteurs supprimées — voir php/config.php pour le nouveau système)
 */

/**
 * Échappe une valeur pour l'affichage HTML.
 */
function e(mixed $val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Formate une valeur avec son unité.
 */
function formatValeur(mixed $valeur, string $unite): string
{
    if ($valeur === null || $valeur === '') return '—';
    return e(number_format((float)$valeur, 1, ',', ' ')) . ' ' . e($unite);
}

/**
 * Retourne une icône SVG selon le type d'actionneur.
 */
function iconeType(string $type): string
{
    $icones = [
        'led'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
        'oled' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
    ];
    return $icones[$type] ?? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
}

/**
 * Récupère la météo extérieure depuis open-meteo (Paris par défaut).
 */
function getMeteoExterieure(float $lat = 48.8566, float $lon = 2.3522): ?array
{
    $champs = 'temperature_2m,apparent_temperature,relative_humidity_2m,'
            . 'precipitation,weathercode,windspeed_10m,winddirection_10m,is_day';
    $url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}"
         . "&current={$champs}&wind_speed_unit=kmh&timezone=Europe%2FParis";
    $ctx     = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $reponse = @file_get_contents($url, false, $ctx);
    if ($reponse === false) return null;
    $data = json_decode($reponse, true);
    if (!isset($data['current'])) return null;
    $c = $data['current'];
    return [
        'temperature'   => $c['temperature_2m']       ?? null,
        'ressentie'     => $c['apparent_temperature']  ?? null,
        'humidite'      => $c['relative_humidity_2m']  ?? null,
        'precipitation' => $c['precipitation']         ?? null,
        'weathercode'   => $c['weathercode']           ?? 0,
        'windspeed'     => $c['windspeed_10m']         ?? null,
        'winddirection' => $c['winddirection_10m']     ?? null,
        'is_day'        => $c['is_day']                ?? 1,
    ];
}

/**
 * Convertit un code météo WMO en description française et icône SVG.
 */
function descriptionMeteo(int $code, bool $isDay = true): array
{
    $nuit  = !$isDay;
    $soleil = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>';
    $lune   = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>';
    $nuage  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 0 1 0 9z"/></svg>';
    $pluie  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="8" y1="19" x2="8" y2="21"/><line x1="8" y1="13" x2="8" y2="15"/><line x1="16" y1="19" x2="16" y2="21"/><line x1="16" y1="13" x2="16" y2="15"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="12" y1="15" x2="12" y2="17"/><path d="M20 16.58A5 5 0 0 0 18 7h-1.26A8 8 0 1 0 4 15.25"/></svg>';
    $neige  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="12" y1="2" x2="12" y2="22"/><path d="M17 7l-5-5-5 5"/><path d="M17 17l-5 5-5-5"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M7 7l-5 5 5 5"/><path d="M17 7l5 5-5 5"/></svg>';
    $orage  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M19 16.9A5 5 0 0 0 18 7h-1.26a8 8 0 1 0-11.62 9"/><polyline points="13 11 9 17 15 17 11 23"/></svg>';
    $brume  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><line x1="3" y1="9" x2="21" y2="9"/><line x1="3" y1="15" x2="21" y2="15"/><line x1="3" y1="12" x2="21" y2="12"/></svg>';

    $map = [
        0  => [$nuit ? 'Nuit claire'    : 'Ensoleillé',      $nuit ? $lune : $soleil],
        1  => ['Peu nuageux',            $nuage],
        2  => ['Partiellement nuageux',  $nuage],
        3  => ['Couvert',                $nuage],
        45 => ['Brouillard',             $brume],
        48 => ['Brouillard givrant',     $brume],
        51 => ['Bruine légère',          $pluie],
        53 => ['Bruine modérée',         $pluie],
        55 => ['Bruine dense',           $pluie],
        61 => ['Pluie légère',           $pluie],
        63 => ['Pluie modérée',          $pluie],
        65 => ['Pluie forte',            $pluie],
        71 => ['Neige légère',           $neige],
        73 => ['Neige modérée',          $neige],
        75 => ['Neige forte',            $neige],
        80 => ['Averses légères',        $pluie],
        81 => ['Averses modérées',       $pluie],
        82 => ['Averses violentes',      $pluie],
        95 => ['Orage',                  $orage],
        96 => ['Orage avec grêle',       $orage],
        99 => ['Orage violent',          $orage],
    ];

    [$label, $icon] = $map[$code] ?? ['Conditions inconnues', $nuage];
    return ['label' => $label, 'icon' => $icon];
}

/**
 * Pagination helper.
 */
function paginer(int $total, int $parPage, int $pageCourante): array
{
    $pages        = max(1, (int)ceil($total / $parPage));
    $pageCourante = max(1, min($pageCourante, $pages));
    $offset       = ($pageCourante - 1) * $parPage;
    return compact('offset', 'pages', 'pageCourante');
}
