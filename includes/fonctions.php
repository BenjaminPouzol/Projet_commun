<?php
/**
 * Fonctions utilitaires — Projet IoT ISEP Salle de sport
 */

require_once __DIR__ . '/../config/db.php';

/**
 * Échappe une valeur pour l'affichage HTML (raccourci).
 */
function e(mixed $val): string
{
    return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Retourne la dernière mesure d'un capteur.
 */
function derniereMesure(int $capteurId): ?array
{
    $stmt = getDB()->prepare(
        'SELECT valeur, horodatage FROM mesures
         WHERE capteur_id = :id
         ORDER BY horodatage DESC LIMIT 1'
    );
    $stmt->execute([':id' => $capteurId]);
    return $stmt->fetch() ?: null;
}

/**
 * Retourne le badge HTML coloré selon le type de capteur et la valeur.
 * Les seuils sont paramétrés pour une salle de sport.
 */
function badgeEtat(string $type, float $valeur): string
{
    $ok = true;
    switch ($type) {
        case 'temperature':
            $ok = ($valeur >= 18 && $valeur <= 26);
            break;
        case 'humidite':
            $ok = ($valeur >= 30 && $valeur <= 75);
            break;
        case 'son':
            $ok = ($valeur <= 85);
            break;
        case 'gaz':
            $ok = ($valeur <= 1000);
            break;
        case 'proximite':
            $ok = ($valeur <= 50);
            break;
    }
    $classe = $ok ? 'badge-ok' : 'badge-alerte';
    $label  = $ok ? 'OK' : 'Alerte';
    return "<span class=\"badge {$classe}\">{$label}</span>";
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
 * Retourne une icône SVG selon le type de capteur.
 */
function iconeType(string $type): string
{
    $icones = [
        'proximite'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="7" r="4"/><path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/></svg>',
        'temperature' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"/></svg>',
        'humidite'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 2.69l5.66 5.66a8 8 0 1 1-11.31 0z"/></svg>',
        'son'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>',
        'gaz'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M8 2h8M12 2v4M5 8h14l-1 12H6L5 8z"/><path d="M9 12h6M10 16h4"/></svg>',
        'led'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>',
        'oled'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
    ];
    return $icones[$type] ?? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
}

/**
 * Récupère la météo extérieure depuis l'API open-meteo (sans clé).
 * Coordonnées par défaut : Paris.
 */
function getMeteoExterieure(float $lat = 48.8566, float $lon = 2.3522): ?array
{
    $url = "https://api.open-meteo.com/v1/forecast?latitude={$lat}&longitude={$lon}"
         . "&current_weather=true&wind_speed_unit=kmh";
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $reponse = @file_get_contents($url, false, $ctx);
    if ($reponse === false) return null;
    $data = json_decode($reponse, true);
    return $data['current_weather'] ?? null;
}

/**
 * Retourne la liste des groupes distincts présents dans la table capteurs.
 */
function getGroupes(): array
{
    return getDB()
        ->query("SELECT DISTINCT groupe FROM capteurs WHERE groupe IS NOT NULL ORDER BY groupe")
        ->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Retourne la liste des types distincts présents dans la table capteurs.
 */
function getTypes(): array
{
    return getDB()
        ->query("SELECT DISTINCT type FROM capteurs ORDER BY type")
        ->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Retourne la liste des équipes distinctes (optionnel : filtrées par groupe).
 */
function getEquipes(string $groupe = ''): array
{
    if ($groupe !== '') {
        $stmt = getDB()->prepare(
            "SELECT DISTINCT equipe FROM capteurs WHERE groupe = :g AND equipe IS NOT NULL ORDER BY equipe"
        );
        $stmt->execute([':g' => $groupe]);
    } else {
        $stmt = getDB()->query("SELECT DISTINCT equipe FROM capteurs WHERE equipe IS NOT NULL ORDER BY equipe");
    }
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Compte le nombre de personnes actuellement dans la salle (dernière mesure proximité).
 */
function getOccupationActuelle(): ?float
{
    $stmt = getDB()->query(
        "SELECT m.valeur FROM mesures m
         JOIN capteurs c ON c.id = m.capteur_id
         WHERE c.type = 'proximite' AND c.actif = 1
         ORDER BY m.horodatage DESC LIMIT 1"
    );
    $row = $stmt->fetch();
    return $row ? (float)$row['valeur'] : null;
}

/**
 * Envoie une alerte e-mail si une mesure dépasse un seuil configuré.
 * N'envoie que si une alerte est définie pour ce capteur.
 */
function verifierEtAlerter(int $capteurId, float $valeur, string $nomCapteur): void
{
    $stmt = getDB()->prepare(
        'SELECT * FROM alertes WHERE capteur_id = :id LIMIT 1'
    );
    $stmt->execute([':id' => $capteurId]);
    $alerte = $stmt->fetch();
    if (!$alerte) return;

    $hors = ($alerte['seuil_min'] !== null && $valeur < $alerte['seuil_min'])
         || ($alerte['seuil_max'] !== null && $valeur > $alerte['seuil_max']);

    if ($hors && !empty($alerte['email'])) {
        $sujet  = "[IoT Salle de sport] Alerte capteur : {$nomCapteur}";
        $corps  = "La valeur {$valeur} du capteur \"{$nomCapteur}\" dépasse les seuils définis.\n"
                . "Seuil min : {$alerte['seuil_min']} | Seuil max : {$alerte['seuil_max']}\n"
                . "Horodatage : " . date('d/m/Y H:i:s');
        @mail($alerte['email'], $sujet, $corps);
    }
}

/**
 * Pagination : retourne un tableau [offset, pages, page_courante].
 */
function paginer(int $total, int $parPage, int $pageCourante): array
{
    $pages = max(1, (int)ceil($total / $parPage));
    $pageCourante = max(1, min($pageCourante, $pages));
    $offset = ($pageCourante - 1) * $parPage;
    return compact('offset', 'pages', 'pageCourante');
}
